<?php

namespace App\Modules\Tenancy;

use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Row-level scoping within the active school. Permissions (RequirePermission) answer
 * "may this kind of user do this kind of thing"; Access answers "to which classes / students".
 *  - school staff (academics.manage): everything in the school
 *  - teacher: sections they are assigned to
 *  - student: own enrolled section(s)
 *  - guardian: only children with an *approved* link
 * Registered `scoped` so memoisation never outlives a request / job.
 */
class Access
{
    private array $memo = [];

    public function __construct(private CurrentSchool $current) {}

    private function remember(string $key, callable $fn): mixed
    {
        return $this->memo[$this->current->id().':'.$key] ??= $fn();
    }

    public function can(User $u, string $permission): bool
    {
        return $this->current->id() !== null && $u->hasPermissionInSchool($permission, $this->current->id());
    }

    public function isStaff(User $u): bool
    {
        return $this->can($u, 'academics.manage');
    }

    public function teacherId(User $u): ?int
    {
        return $this->remember("t{$u->id}", fn () => Teacher::where('user_id', $u->id)->value('id'));
    }

    /** @return list<int> student ids this user *is* (student) or may see (approved guardian links) */
    public function studentIds(User $u): array
    {
        return $this->remember("s{$u->id}", function () use ($u) {
            $own = Student::where('user_id', $u->id)->pluck('id');
            $children = StudentGuardian::where('status', 'approved')
                ->whereIn('guardian_id', Guardian::where('user_id', $u->id)->select('id'))->pluck('student_id');

            return $own->merge($children)->unique()->values()->all();
        });
    }

    /** Only the student ids the user *is* (not guardian children) — for acting as a student. */
    public function ownStudentId(User $u): ?int
    {
        return $this->remember("o{$u->id}", fn () => Student::where('user_id', $u->id)->value('id'));
    }

    /** @return list<int>|null null => every section */
    public function sectionIds(User $u): ?array
    {
        if ($this->isStaff($u)) {
            return null;
        }

        return $this->remember("sec{$u->id}", function () use ($u) {
            $ids = collect();
            if ($tid = $this->teacherId($u)) {
                $ids = $ids->merge(TeacherAssignment::where('teacher_id', $tid)->pluck('section_id'));
            }
            if ($sids = $this->studentIds($u)) {
                $ids = $ids->merge(Enrollment::whereIn('student_id', $sids)->where('status', 'active')->pluck('section_id'));
            }

            return $ids->unique()->values()->all();
        });
    }

    public function canViewSection(User $u, int $sectionId): bool
    {
        $ids = $this->sectionIds($u);

        return $ids === null || in_array($sectionId, $ids, true);
    }

    /** Teaching rights: assigned to (section, subject) — or staff. */
    public function canTeach(User $u, int $sectionId, ?int $subjectId = null): bool
    {
        if ($this->isStaff($u)) {
            return true;
        }
        $tid = $this->teacherId($u);
        if (! $tid) {
            return false;
        }

        return TeacherAssignment::where('teacher_id', $tid)->where('section_id', $sectionId)
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))->exists();
    }

    /** May this user see data of this student? (self, approved guardian, teacher of the student's section, staff) */
    public function canViewStudent(User $u, int $studentId): bool
    {
        if ($this->isStaff($u) || in_array($studentId, $this->studentIds($u), true)) {
            return true;
        }
        $tid = $this->teacherId($u);

        return $tid && DB::table('enrollments as e')
            ->join('teacher_assignments as ta', 'ta.section_id', '=', 'e.section_id')
            ->where('e.student_id', $studentId)->where('e.status', 'active')->where('ta.teacher_id', $tid)
            ->where('e.school_id', $this->current->id())->exists();
    }

    /** Active section of a student (current enrolment). */
    public function sectionOfStudent(int $studentId): ?int
    {
        return Enrollment::where('student_id', $studentId)->where('status', 'active')->latest('id')->value('section_id');
    }
}
