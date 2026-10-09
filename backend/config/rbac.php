<?php

/**
 * Permission matrix (source of truth; synced to DB by `php artisan rbac:sync`).
 * Platform roles (super_admin, support) live in users.platform_role, not here.
 */
return [
    'permissions' => [
        'school.settings'  => 'Edit school profile and settings',
        'people.manage'    => 'Create/edit teachers, students, guardians, memberships',
        'academics.view'   => 'View years, grades, sections, subjects, assignments',
        'academics.manage' => 'Manage years, grades, sections, subjects, assignments, enrollments',
        'schedule.view'    => 'View the full school timetable',
        'schedule.manage'  => 'Edit timetable, bell periods, calendar, substitutions',
        'schedule.own'     => 'View own schedule (teacher/student)',
        'audit.view'       => 'View school audit log',
    ],

    'roles' => [
        'school_admin' => ['name' => 'مدیر مدرسه', 'permissions' => ['*']],
        'deputy'       => ['name' => 'معاون و مسئول آموزشی', 'permissions' => [
            'people.manage', 'academics.view', 'academics.manage', 'schedule.view', 'schedule.manage', 'schedule.own',
        ]],
        'teacher'      => ['name' => 'معلم', 'permissions' => ['academics.view', 'schedule.own']],
        'student'      => ['name' => 'دانش‌آموز', 'permissions' => ['schedule.own']],
        'guardian'     => ['name' => 'والد یا سرپرست', 'permissions' => []],
    ],
];
