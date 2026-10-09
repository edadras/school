<?php

namespace App\Modules\Academics;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Validation\Rule;

/**
 * Declarative definition of the simple tenant CRUD resources. Complex flows
 * (teachers, assignments, enrollments, timetable) have dedicated services.
 */
class ResourceRegistry
{
    public static function keys(): array
    {
        return ['academic-years', 'grades', 'subjects', 'sections', 'students', 'calendar-events'];
    }

    public static function model(string $key): string
    {
        return [
            'academic-years' => AcademicYear::class, 'grades' => Grade::class, 'subjects' => Subject::class,
            'sections' => Section::class, 'students' => Student::class, 'calendar-events' => \App\Models\SchoolCalendarEvent::class,
        ][$key] ?? abort(404);
    }

    /** exists() rule limited to the active school — a foreign-school id fails validation. */
    public static function existsInSchool(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where('school_id', app(CurrentSchool::class)->id());
    }

    public static function rules(string $key, bool $update, ?int $id = null): array
    {
        $req = $update ? 'sometimes' : 'required';
        $school = app(CurrentSchool::class)->id();
        $uniqueIn = fn (string $table, string $col) => Rule::unique($table, $col)->where('school_id', $school)->ignore($id);

        return match ($key) {
            'academic-years' => [
                'title' => [$req, 'string', 'max:60', $uniqueIn('academic_years', 'title')],
                'starts_on' => [$req, 'date'], 'ends_on' => [$req, 'date', 'after:starts_on'],
                'is_current' => ['sometimes', 'boolean'],
            ],
            'grades' => [
                'name' => [$req, 'string', 'max:60', $uniqueIn('grades', 'name')],
                'stage' => ['nullable', 'string', 'max:40'], 'level' => ['sometimes', 'integer', 'between:1,20'],
            ],
            'subjects' => [
                'name' => [$req, 'string', 'max:100'], 'code' => ['nullable', 'string', 'max:30'],
                'grade_id' => ['nullable', self::existsInSchool('grades')],
                'coefficient' => ['sometimes', 'numeric', 'between:0,99'],
            ],
            'sections' => [
                'grade_id' => [$req, self::existsInSchool('grades')],
                'academic_year_id' => [$req, self::existsInSchool('academic_years')],
                'name' => [$req, 'string', 'max:60'], 'track' => ['nullable', 'string', 'max:60'],
                'capacity' => ['sometimes', 'integer', 'between:1,500'],
            ],
            'calendar-events' => [
                'type' => [$req, Rule::in(['holiday', 'exceptional', 'meeting', 'event'])],
                'title' => [$req, 'string', 'max:120'], 'starts_on' => [$req, 'date'],
                'ends_on' => [$req, 'date', 'after_or_equal:starts_on'], 'cancels_classes' => ['sometimes', 'boolean'],
            ],
            'students' => [
                'first_name' => [$req, 'string', 'max:80'], 'last_name' => [$req, 'string', 'max:80'],
                'student_code' => [$req, 'string', 'max:40', $uniqueIn('students', 'student_code')],
                'birth_date' => ['nullable', 'date', 'before:today'],
                'status' => ['sometimes', Rule::in(['active', 'transferred', 'graduated', 'archived'])],
                'custom_fields' => ['nullable', 'array', 'max:20'],
                'custom_fields.*' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    /** Returns an error message when the record still has dependents, else null. */
    public static function deleteBlocker(string $key, $model): ?string
    {
        $has = fn (string $table, string $col) => \DB::table($table)->where($col, $model->id)->exists();

        return match ($key) {
            'academic-years' => 'سال تحصیلی حذف نمی‌شود؛ بایگانی کنید.',
            'students' => 'دانش‌آموز حذف نمی‌شود؛ وضعیت را «بایگانی» کنید.',
            'grades' => ($has('sections', 'grade_id') || $has('subjects', 'grade_id')) ? 'این پایه کلاس یا درس وابسته دارد.' : null,
            'subjects' => ($has('teacher_assignments', 'subject_id') || $has('timetable_entries', 'subject_id')) ? 'این درس در تخصیص یا برنامه استفاده شده است.' : null,
            'sections' => ($has('enrollments', 'section_id') || $has('teacher_assignments', 'section_id')) ? 'این کلاس دانش‌آموز یا تخصیص دارد.' : null,
            default => null,
        };
    }
}
