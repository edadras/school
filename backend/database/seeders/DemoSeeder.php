<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolSubscription;
use App\Models\SchoolUserMembership;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Local-only demo data. Passwords are random per run and printed once; nothing secret is committed. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = Str::password(16, symbols: false);

        $school = School::firstOrCreate(['code' => 'demo'], ['name' => 'مدرسه نمونه', 'city' => 'تهران', 'timezone' => 'Asia/Tehran']);
        $school->forceFill(['status' => 'active', 'activated_at' => now()])->save();
        SchoolSubscription::firstOrCreate(['school_id' => $school->id], ['plan' => 'demo', 'max_students' => 200, 'max_teachers' => 30]);

        $mk = function (string $email, string $name, string $role) use ($school, $password) {
            $u = User::firstOrNew(['email' => $email]);
            $u->fill(['name' => $name, 'password' => $password])->save();
            SchoolUserMembership::firstOrCreate(['school_id' => $school->id, 'user_id' => $u->id, 'role_id' => Role::where('key', $role)->value('id')]);

            return $u;
        };
        $mk('admin@demo.test', 'مدیر نمونه', 'school_admin');
        $teacherUser = $mk('teacher@demo.test', 'معلم نمونه', 'teacher');

        app(CurrentSchool::class)->run($school, function () use ($teacherUser) {
            $year = AcademicYear::firstOrCreate(['title' => '1405-1406'], ['starts_on' => '2026-09-23', 'ends_on' => '2027-06-20', 'is_current' => true]);
            $grade = Grade::firstOrCreate(['name' => 'هفتم'], ['stage' => 'متوسطه اول', 'level' => 7]);
            $section = Section::firstOrCreate(['grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'الف']);
            $math = Subject::firstOrCreate(['name' => 'ریاضی', 'grade_id' => $grade->id]);
            $teacher = Teacher::firstOrCreate(['user_id' => $teacherUser->id]);
            TeacherAssignment::firstOrCreate(['section_id' => $section->id, 'subject_id' => $math->id], ['teacher_id' => $teacher->id]);
        });

        $this->command?->info("Demo logins: admin@demo.test / teacher@demo.test  password: $password");
    }
}
