<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Role;
use App\Models\SchoolUserMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Guardian ↔ student links. A guardian sees a child only through an *approved* link. */
class GuardianController extends Controller
{
    public function store(Request $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:20'], 'relation' => ['sometimes', 'string', 'max:30'],
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        $schoolId = app(CurrentSchool::class)->id();

        $link = DB::transaction(function () use ($data, $student, $schoolId, $request) {
            $user = User::where('email', $data['email'])->first()
                ?? User::create(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => $data['password']]);
            SchoolUserMembership::firstOrCreate(['school_id' => $schoolId, 'user_id' => $user->id, 'role_id' => Role::where('key', 'guardian')->value('id')], ['status' => 'active']);
            $guardian = Guardian::firstOrCreate(['user_id' => $user->id], ['phone' => $data['phone'] ?? null]);

            // Created by school staff => approved immediately; recorded for audit.
            return StudentGuardian::updateOrCreate(
                ['student_id' => $student->id, 'guardian_id' => $guardian->id],
                ['relation' => $data['relation'] ?? 'parent', 'status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()],
            );
        });
        Audit::record('guardian.linked', $student, null, ['guardian_id' => $link->guardian_id]);

        return response()->json(['data' => $link], 201);
    }

    public function revoke(int $studentId, int $guardianId): JsonResponse
    {
        $link = StudentGuardian::where('student_id', $studentId)->where('guardian_id', $guardianId)->firstOrFail();
        $link->update(['status' => 'revoked']);
        Audit::record('guardian.revoked', $link);

        return response()->json(['data' => $link]);
    }

    /** Create the student's own login (students log in with email or phone; no account = no access). */
    public function createAccount(Request $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);
        abort_if($student->user_id, 422, 'برای این دانش‌آموز قبلاً حساب ساخته شده است.');
        $d = $request->validate([
            'email' => ['required_without:phone', 'nullable', 'email', 'unique:users,email'], 'phone' => ['required_without:email', 'nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        $schoolId = app(CurrentSchool::class)->id();
        DB::transaction(function () use ($student, $d, $schoolId) {
            $user = User::create(['name' => $student->first_name.' '.$student->last_name, 'email' => $d['email'] ?? null, 'phone' => $d['phone'] ?? null, 'password' => $d['password']]);
            SchoolUserMembership::create(['school_id' => $schoolId, 'user_id' => $user->id, 'role_id' => Role::where('key', 'student')->value('id')]);
            $student->update(['user_id' => $user->id]);
        });
        Audit::record('student.account_created', $student);

        return response()->json(['data' => $student->only(['id', 'user_id'])], 201);
    }

    /** Teachers who teach the guardian's (approved) children — the only people a parent may request a meeting with. */
    public function childTeachers(Request $request): JsonResponse
    {
        $access = app(\App\Modules\Tenancy\Access::class);
        $sections = \App\Models\Enrollment::whereIn('student_id', $access->studentIds($request->user()))->where('status', 'active')->pluck('section_id');
        $rows = \App\Models\TeacherAssignment::whereIn('section_id', $sections)->get();
        $teachers = \App\Models\Teacher::with('user:id,name')->whereIn('id', $rows->pluck('teacher_id'))->get();
        $subjects = \App\Models\Subject::whereIn('id', $rows->pluck('subject_id'))->pluck('name', 'id');

        return response()->json(['data' => $teachers->map(fn ($t) => ['id' => $t->id, 'label' => $t->user->name.' ('.$rows->where('teacher_id', $t->id)->map(fn ($r) => $subjects[$r->subject_id] ?? '')->unique()->implode('، ').')'])->values()]);
    }

    /** Children visible to the logged-in guardian: approved links only. */
    public function myChildren(Request $request): JsonResponse
    {
        $children = Student::query()
            ->whereIn('id', StudentGuardian::query()
                ->where('status', 'approved')
                ->whereIn('guardian_id', Guardian::where('user_id', $request->user()->id)->select('id'))
                ->select('student_id'))
            ->get(['id', 'first_name', 'last_name', 'student_code', 'status']);
        $sections = \App\Models\Enrollment::whereIn('student_id', $children->pluck('id'))->where('status', 'active')->get()->keyBy('student_id');
        $names = \App\Models\Section::with('grade:id,name')->whereIn('id', $sections->pluck('section_id'))->get()->mapWithKeys(fn ($x) => [$x->id => trim(($x->grade->name ?? '').' '.$x->name)]);

        return response()->json(['data' => $children->map(fn ($c) => $c->toArray() + ['section_id' => $sections[$c->id]->section_id ?? null, 'section' => $names[$sections[$c->id]->section_id ?? 0] ?? null])->values()]);
    }
}
