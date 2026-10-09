<?php

namespace App\Modules\Imports;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\ImportJob;
use App\Models\Role;
use App\Models\SchoolUserMembership;
use App\Models\Section;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Bulk import of students (+ optional guardian + optional class) and teachers from CSV/XLSX.
 * Row-level validation with a per-row error report; valid rows are created even if others fail
 * (unless dry_run). Header names are matched case-insensitively; Persian digits are normalised.
 */
class ImportService
{
    public const STUDENT_COLUMNS = ['student_code', 'first_name', 'last_name', 'birth_date', 'grade', 'section', 'guardian_name', 'guardian_email', 'guardian_phone'];

    public const TEACHER_COLUMNS = ['name', 'email', 'phone', 'personnel_code'];

    public function __construct(private CurrentSchool $current) {}

    /** @return list<array<string,string>> */
    public function readRows(string $path, string $ext): array
    {
        $reader = strtolower($ext) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $rows = [];
        $header = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => trim((string) ($c->getValue() instanceof \DateTimeInterface ? $c->getValue()->format('Y-m-d') : $c->getValue())), $row->cells);
                if ($header === null) {
                    $header = array_map(fn ($h) => strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $h)), $cells);
                    continue;
                }
                if (! array_filter($cells, 'strlen')) {
                    continue;
                }
                $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            }
            break; // first sheet only
        }
        $reader->close();

        return $rows;
    }

    public function importStudents(User $by, array $rows, bool $dryRun, string $password): ImportJob
    {
        $errors = [];
        $created = 0;
        $limit = (int) DB::table('school_subscriptions')->where('school_id', $this->current->id())->where('status', 'active')->value('max_students');
        $existing = Student::count();
        $year = \App\Models\AcademicYear::where('is_current', true)->first();

        foreach ($rows as $i => $r) {
            $line = $i + 2;   // header is line 1
            $r = $this->norm($r);
            $v = Validator::make($r, [
                'student_code' => ['required', 'string', 'max:40'], 'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
                'birth_date' => ['nullable', 'date', 'before:today'], 'guardian_email' => ['nullable', 'email'],
            ], [], ['student_code' => 'کد دانش‌آموزی', 'first_name' => 'نام', 'last_name' => 'نام خانوادگی']);
            if ($v->fails()) {
                $errors[] = ['line' => $line, 'errors' => $v->errors()->all()];
                continue;
            }
            if (Student::where('student_code', $r['student_code'])->exists()) {
                $errors[] = ['line' => $line, 'errors' => ['کد دانش‌آموزی تکراری است.']];
                continue;
            }
            $section = null;
            if (! empty($r['grade']) && ! empty($r['section'])) {
                $grade = Grade::where('name', $r['grade'])->first();
                $section = $grade && $year ? Section::where('grade_id', $grade->id)->where('academic_year_id', $year->id)->where('name', $r['section'])->first() : null;
                if (! $section) {
                    $errors[] = ['line' => $line, 'errors' => ['پایه/کلاس یافت نشد.']];
                    continue;
                }
            }
            if ($limit && $existing + $created >= $limit) {
                $errors[] = ['line' => $line, 'errors' => ['سقف تعداد دانش‌آموزان طرح اشتراک پر شده است.']];
                continue;
            }
            if ($dryRun) {
                $created++;
                continue;
            }
            try {
                DB::transaction(function () use ($r, $section, $password, $by) {
                    $stu = Student::create(['student_code' => $r['student_code'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'birth_date' => $r['birth_date'] ?: null]);
                    if ($section) {
                        Enrollment::create(['student_id' => $stu->id, 'section_id' => $section->id, 'academic_year_id' => $section->academic_year_id, 'enrolled_on' => today()]);
                    }
                    if (! empty($r['guardian_email'])) {
                        $gu = User::where('email', $r['guardian_email'])->first() ?? User::create(['name' => $r['guardian_name'] ?: 'والد '.$r['last_name'], 'email' => $r['guardian_email'], 'phone' => $r['guardian_phone'] ?: null, 'password' => $password]);
                        SchoolUserMembership::firstOrCreate(['school_id' => $this->current->id(), 'user_id' => $gu->id, 'role_id' => Role::where('key', 'guardian')->value('id')], ['status' => 'active']);
                        $g = \App\Models\Guardian::firstOrCreate(['user_id' => $gu->id], ['phone' => $r['guardian_phone'] ?: null]);
                        \App\Models\StudentGuardian::create(['student_id' => $stu->id, 'guardian_id' => $g->id, 'status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);
                    }
                });
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['line' => $line, 'errors' => ['خطای ذخیره‌سازی: '.mb_substr($e->getMessage(), 0, 120)]];
            }
        }

        return $this->finish($by, 'students', count($rows), $created, $errors, $dryRun);
    }

    public function importTeachers(User $by, array $rows, bool $dryRun, string $password): ImportJob
    {
        $errors = [];
        $created = 0;
        $max = (int) DB::table('school_subscriptions')->where('school_id', $this->current->id())->where('status', 'active')->value('max_teachers');
        $count = Teacher::count();
        foreach ($rows as $i => $r) {
            $r = $this->norm($r);
            $v = Validator::make($r, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'], 'phone' => ['nullable', 'string', 'max:20']]);
            if ($v->fails()) {
                $errors[] = ['line' => $i + 2, 'errors' => $v->errors()->all()];
                continue;
            }
            $user = User::where('email', $r['email'])->first();
            if ($user && Teacher::where('user_id', $user->id)->exists()) {
                $errors[] = ['line' => $i + 2, 'errors' => ['این معلم قبلاً ثبت شده است.']];
                continue;
            }
            if ($max && $count + $created >= $max) {
                $errors[] = ['line' => $i + 2, 'errors' => ['سقف تعداد معلمان طرح اشتراک پر شده است.']];
                continue;
            }
            if (! $dryRun) {
                DB::transaction(function () use ($r, $user, $password) {
                    $user ??= User::create(['name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'] ?: null, 'password' => $password]);
                    SchoolUserMembership::firstOrCreate(['school_id' => $this->current->id(), 'user_id' => $user->id, 'role_id' => Role::where('key', 'teacher')->value('id')], ['status' => 'active']);
                    Teacher::create(['user_id' => $user->id, 'personnel_code' => $r['personnel_code'] ?: null]);
                });
            }
            $created++;
        }

        return $this->finish($by, 'teachers', count($rows), $created, $errors, $dryRun);
    }

    private function finish(User $by, string $type, int $total, int $created, array $errors, bool $dry): ImportJob
    {
        $job = ImportJob::create(['user_id' => $by->id, 'type' => $type.($dry ? '_dry' : ''), 'status' => 'done', 'total' => $total, 'created' => $created, 'failed' => count($errors), 'errors' => $errors]);
        $dry || Audit::record("import.$type", $job, null, ['total' => $total, 'created' => $created, 'failed' => count($errors)]);

        return $job;
    }

    private function norm(array $r): array
    {
        return array_map(fn ($v) => strtr((string) $v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']), $r) + array_fill_keys(array_merge(self::STUDENT_COLUMNS, self::TEACHER_COLUMNS), '');
    }
}
