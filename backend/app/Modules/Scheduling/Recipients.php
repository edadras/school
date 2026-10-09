<?php

namespace App\Modules\Scheduling;

use Illuminate\Support\Facades\DB;

/** Resolves which users are affected by a timetable entry (teacher, students, approved guardians). */
class Recipients
{
    /** @return list<int> */
    public static function forSection(int $schoolId, int $sectionId): array
    {
        $students = DB::table('enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->where('e.school_id', $schoolId)->where('e.section_id', $sectionId)->where('e.status', 'active')
            ->whereNotNull('s.user_id')->pluck('s.user_id');

        $guardians = DB::table('enrollments as e')
            ->join('student_guardians as sg', 'sg.student_id', '=', 'e.student_id')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('e.school_id', $schoolId)->where('e.section_id', $sectionId)->where('e.status', 'active')
            ->where('sg.status', 'approved')->pluck('g.user_id');

        return $students->merge($guardians)->unique()->values()->all();
    }

    public static function teacherUser(int $teacherId): ?int
    {
        return DB::table('teachers')->where('id', $teacherId)->value('user_id');
    }
}
