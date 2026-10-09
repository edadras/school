<?php

return [
    // Types that also go out by email when the user has not opted out. Everything else: in-app + realtime + push.
    'email_types' => ['reportcard.issued', 'announcement', 'attendance.absent', 'exam.result', 'school.approved'],
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),   // path to the JSON key file (never committed)
    ],
];
