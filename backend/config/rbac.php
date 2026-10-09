<?php

/**
 * Permission matrix (source of truth; synced to DB by `php artisan rbac:sync`).
 * Platform roles (super_admin, support) live in users.platform_role, not here.
 * NOTE: a permission says *what kind* of action is allowed; *which rows* (own sections,
 * own children…) is always narrowed further by App\Modules\Tenancy\Access.
 */
return [
    'permissions' => [
        'school.settings'      => 'Edit school profile, settings and policies',
        'people.manage'        => 'Create/edit teachers, students, guardians, memberships',
        'academics.view'       => 'View years, grades, sections, subjects, assignments',
        'academics.manage'     => 'Manage years, grades, sections, subjects, assignments, enrollments',
        'schedule.view'        => 'View the full school timetable',
        'schedule.manage'      => 'Edit timetable, bell periods, calendar, substitutions',
        'schedule.own'         => 'View own schedule (teacher/student)',
        'audit.view'           => 'View school audit log',
        'attendance.take'      => 'Record/confirm attendance for own classes',
        'attendance.view_all'  => 'View attendance of every class',
        'content.manage'       => 'Publish materials and assignments for own classes',
        'learn.participate'    => 'Student: open materials, submit work, take exams',
        'guardian.access'      => 'Guardian: view approved children',
        'exams.manage'         => 'Question bank and exams for own classes',
        'grades.enter'         => 'Enter grades for own classes',
        'grades.approve'       => 'Approve/finalise grades and change approved grades',
        'reportcards.manage'   => 'Templates, generate and issue report cards',
        'sessions.host'        => 'Host live classes for own classes',
        'sessions.monitor'     => 'Monitor every live class',
        'messaging.use'        => 'Use the messenger within school policy',
        'messaging.moderate'   => 'Handle reported messages, lock conversations',
        'announcements.manage' => 'Publish school announcements',
        'ai.use_student'       => 'AI tutor (student mode)',
        'ai.use_teacher'       => 'AI teaching assistant',
        'ai.use_admin'         => 'AI management insights',
        'data.export'          => 'Export school data',
        'data.import'          => 'Bulk import users',
        'support.grant'        => 'Grant time-limited support access',
    ],

    'roles' => [
        'school_admin' => ['name' => 'مدیر مدرسه', 'permissions' => ['*']],
        'deputy'       => ['name' => 'معاون و مسئول آموزشی', 'permissions' => [
            'people.manage', 'academics.view', 'academics.manage', 'schedule.view', 'schedule.manage', 'schedule.own',
            'attendance.take', 'attendance.view_all', 'sessions.monitor', 'grades.approve', 'reportcards.manage',
            'messaging.use', 'messaging.moderate', 'announcements.manage', 'ai.use_admin', 'data.export', 'data.import',
        ]],
        'teacher'      => ['name' => 'معلم', 'permissions' => [
            'academics.view', 'schedule.own', 'attendance.take', 'content.manage', 'exams.manage', 'grades.enter',
            'sessions.host', 'messaging.use', 'ai.use_teacher',
        ]],
        'student'      => ['name' => 'دانش‌آموز', 'permissions' => ['schedule.own', 'learn.participate', 'messaging.use', 'ai.use_student']],
        'guardian'     => ['name' => 'والد یا سرپرست', 'permissions' => ['guardian.access', 'messaging.use']],
    ],
];
