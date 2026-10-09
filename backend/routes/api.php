<?php

use App\Modules\Academics\Http\AssignmentController;
use App\Modules\Academics\Http\EnrollmentController;
use App\Modules\Academics\Http\GuardianController;
use App\Modules\Academics\Http\ResourceController;
use App\Modules\Academics\Http\TeacherController;
use App\Modules\Auth\Http\AuthController;
use App\Modules\Scheduling\Http\NotificationController;
use App\Modules\Scheduling\Http\TimetableController;
use App\Modules\Schools\Http\PlatformSchoolController;
use App\Modules\Schools\Http\SchoolRegistrationController;
use App\Modules\AI\Http\AiController;
use App\Modules\Administration\Http\PlatformController;
use App\Modules\Administration\Http\SchoolAdminController;
use App\Modules\Analytics\Http\AnalyticsController;
use App\Modules\Attendance\Http\AttendanceController;
use App\Modules\Exams\Http\ExamController;
use App\Modules\Exams\Http\QuestionController;
use App\Modules\Files\Http\FileController;
use App\Modules\Grading\Http\GradeController;
use App\Modules\Imports\Http\ImportController;
use App\Modules\Learning\Http\AssignmentController as LearningAssignmentController;
use App\Modules\Learning\Http\MaterialController;
use App\Modules\Messaging\Http\MessagingController;
use App\Modules\Notifications\Http\PreferenceController;
use App\Modules\ReportCards\Http\ReportCardController;
use App\Modules\Support\Http\MeetingController;
use App\Modules\Support\Http\SupportController;
use App\Modules\VirtualClassrooms\Http\SessionController;
use App\Modules\VirtualClassrooms\Http\WebhookController;
use Illuminate\Support\Facades\Route;

// All routes are served under /api/v1 (see bootstrap/app.php).

// ---- Public -----------------------------------------------------------------
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
Route::post('schools/register', [SchoolRegistrationController::class, 'register'])->middleware('throttle:school-register');

// Signed, short-lived private file download (the signature is the credential).
Route::get('files/{id}/download', [FileController::class, 'download'])->middleware(['signed', 'throttle:120,1'])->name('files.download')->whereNumber('id');
// SFU → platform events (verified by signature inside the controller).
Route::post('webhooks/livekit', [WebhookController::class, 'livekit']);

// ---- Authenticated (no school context) -------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // School owner follows up on / resubmits their own registration while still "pending".
    Route::get('schools/my-request', [SchoolRegistrationController::class, 'myRequest']);
    Route::post('schools/my-request/resubmit', [SchoolRegistrationController::class, 'resubmit']);

    // ---- Platform (super admin) ------------------------------------------
    Route::prefix('platform')->middleware('platform.role:super_admin,support')->group(function () {
        Route::get('support/tickets', [SupportController::class, 'platformIndex']);
        Route::get('support/tickets/{id}', [SupportController::class, 'platformShow'])->whereNumber('id');
        Route::post('support/tickets/{id}/reply', [SupportController::class, 'platformReply'])->whereNumber('id');
    });
    Route::prefix('platform')->middleware('platform.role:super_admin')->group(function () {
        Route::get('stats', [PlatformController::class, 'stats']);
        Route::get('health', [PlatformController::class, 'health']);
        Route::get('announcements', [PlatformController::class, 'announcements']);
        Route::post('announcements', [PlatformController::class, 'publishAnnouncement']);
        Route::get('operators', [PlatformController::class, 'operators']);
        Route::post('operators', [PlatformController::class, 'createOperator']);
        Route::patch('operators/{id}', [PlatformController::class, 'updateOperator'])->whereNumber('id');
        Route::get('settings', [PlatformController::class, 'settings']);
        Route::patch('settings', [PlatformController::class, 'updateSettings']);
        Route::get('audit', [PlatformController::class, 'audit']);
        Route::get('approval-requests', [PlatformSchoolController::class, 'requests']);
        Route::post('approval-requests/{approvalRequest}/decision', [PlatformSchoolController::class, 'decide']);
        Route::get('schools', [PlatformSchoolController::class, 'schools']);
        Route::post('schools/{school}/suspend', [PlatformSchoolController::class, 'suspend']);
        Route::post('schools/{school}/reactivate', [PlatformSchoolController::class, 'reactivate']);
        Route::patch('schools/{school}/subscription', [PlatformSchoolController::class, 'updateSubscription']);
    });

    // ---- School-scoped (tenant resolved & verified server-side) ----------------
    Route::middleware('school')->group(function () {
        Route::get('me/schedule', [TimetableController::class, 'mine'])->middleware('perm:schedule.own');
        Route::get('me/notifications', [NotificationController::class, 'index']);
        Route::post('me/notifications/{id}/read', [NotificationController::class, 'read']);
        Route::get('me/teaching', [AssignmentController::class, 'mine']);
        Route::get('me/children', [GuardianController::class, 'myChildren']);
        Route::get('me/child-teachers', [GuardianController::class, 'childTeachers'])->middleware('perm:guardian.access');
        Route::get('students/{studentId}/schedule', [TimetableController::class, 'ofStudent'])->whereNumber('studentId');

        Route::middleware('perm:academics.view')->group(function () {
            Route::get('academics/{resource}', [ResourceController::class, 'index'])->whereIn('resource', \App\Modules\Academics\ResourceRegistry::keys());
            Route::get('academics/{resource}/{id}', [ResourceController::class, 'show'])->whereIn('resource', \App\Modules\Academics\ResourceRegistry::keys())->whereNumber('id');
            Route::get('teachers', [TeacherController::class, 'index']);
            Route::get('teacher-assignments', [AssignmentController::class, 'index']);
            Route::get('enrollments', [EnrollmentController::class, 'index']);
        });

        Route::middleware('perm:academics.manage')->group(function () {
            Route::post('academics/{resource}', [ResourceController::class, 'store'])->whereIn('resource', \App\Modules\Academics\ResourceRegistry::keys());
            Route::patch('academics/{resource}/{id}', [ResourceController::class, 'update'])->whereIn('resource', \App\Modules\Academics\ResourceRegistry::keys())->whereNumber('id');
            Route::delete('academics/{resource}/{id}', [ResourceController::class, 'destroy'])->whereIn('resource', \App\Modules\Academics\ResourceRegistry::keys())->whereNumber('id');
            Route::post('teacher-assignments', [AssignmentController::class, 'store']);
            Route::delete('teacher-assignments/{id}', [AssignmentController::class, 'destroy']);
            Route::post('enrollments', [EnrollmentController::class, 'store']);
        });

        Route::middleware('perm:people.manage')->group(function () {
            Route::post('teachers', [TeacherController::class, 'store']);
            Route::post('students/{studentId}/guardians', [GuardianController::class, 'store']);
            Route::delete('students/{studentId}/guardians/{guardianId}', [GuardianController::class, 'revoke']);
        });

        Route::middleware('perm:schedule.view')->group(function () {
            Route::get('timetables', [TimetableController::class, 'index']);
            Route::get('timetables/{id}', [TimetableController::class, 'show']);
        });

        Route::middleware('perm:schedule.manage')->group(function () {
            Route::post('timetables', [TimetableController::class, 'store']);
            Route::post('timetables/{id}/check', [TimetableController::class, 'check']);
            Route::post('timetables/{id}/entries', [TimetableController::class, 'addEntry']);
            Route::delete('timetables/{id}/entries/{entryId}', [TimetableController::class, 'removeEntry']);
            Route::post('timetables/{id}/activate', [TimetableController::class, 'activate']);
            Route::post('substitutions', [TimetableController::class, 'substitute']);
        });

        // ================= Stage 2: learning, live classes, messaging, assessment, reports, AI =================
        // --- files (private storage + signed links)
        Route::post('files', [FileController::class, 'store'])->middleware('throttle:60,1');
        Route::get('files/{id}/link', [FileController::class, 'link'])->whereNumber('id');
        Route::delete('files/{id}', [FileController::class, 'destroy'])->whereNumber('id');

        // --- notifications preferences / devices
        Route::get('me/notification-preferences', [PreferenceController::class, 'index']);
        Route::put('me/notification-preferences', [PreferenceController::class, 'update']);
        Route::post('me/devices', [PreferenceController::class, 'registerDevice']);
        Route::delete('me/devices', [PreferenceController::class, 'removeDevice']);

        // --- school profile, settings, audit, announcements, notes
        Route::get('school/profile', [SchoolAdminController::class, 'profile']);
        Route::patch('school/profile', [SchoolAdminController::class, 'updateProfile'])->middleware('perm:school.settings');
        Route::patch('school/settings', [SchoolAdminController::class, 'updateSettings'])->middleware('perm:school.settings');
        Route::get('school/audit', [SchoolAdminController::class, 'audit'])->middleware('perm:audit.view');
        Route::get('announcements', [SchoolAdminController::class, 'announcements']);
        Route::post('announcements', [SchoolAdminController::class, 'publishAnnouncement'])->middleware('perm:announcements.manage');
        Route::get('students/{studentId}/notes', [SchoolAdminController::class, 'notes'])->whereNumber('studentId');
        Route::post('students/{studentId}/notes', [SchoolAdminController::class, 'addNote'])->whereNumber('studentId');

        // --- meeting requests (guardian ↔ school)
        Route::get('meetings', [MeetingController::class, 'index']);
        Route::post('meetings', [MeetingController::class, 'store'])->middleware('perm:guardian.access');
        Route::put('meetings/{id}/respond', [MeetingController::class, 'respond'])->whereNumber('id');

        // --- support
        Route::get('support/tickets', [SupportController::class, 'mine']);
        Route::post('support/tickets', [SupportController::class, 'store'])->middleware('throttle:10,1');
        Route::get('support/tickets/{id}', [SupportController::class, 'show'])->whereNumber('id');
        Route::post('support/tickets/{id}/reply', [SupportController::class, 'reply'])->whereNumber('id');
        Route::middleware('perm:support.grant')->group(function () {
            Route::get('support/grants', [SupportController::class, 'grants']);
            Route::post('support/grants', [SupportController::class, 'grant']);
            Route::delete('support/grants/{id}', [SupportController::class, 'revoke'])->whereNumber('id');
        });

        // --- import / export
        Route::middleware('perm:data.import')->group(function () {
            Route::post('imports/{type}', [ImportController::class, 'import'])->middleware('throttle:10,1');
            Route::get('imports/{type}/template', [ImportController::class, 'template']);
        });
        Route::get('exports/{type}', [ImportController::class, 'export'])->middleware(['perm:data.export', 'throttle:10,1']);

        // --- materials / assignments / submissions
        Route::get('materials', [MaterialController::class, 'index']);
        Route::post('materials', [MaterialController::class, 'store'])->middleware('perm:content.manage');
        Route::delete('materials/{id}', [MaterialController::class, 'destroy'])->middleware('perm:content.manage')->whereNumber('id');
        Route::get('assignments', [LearningAssignmentController::class, 'index']);
        Route::get('assignments/{id}', [LearningAssignmentController::class, 'show'])->whereNumber('id');
        Route::middleware('perm:content.manage')->group(function () {
            Route::post('assignments', [LearningAssignmentController::class, 'store']);
            Route::post('assignments/{id}/publish', [LearningAssignmentController::class, 'publish'])->whereNumber('id');
            Route::post('assignments/{id}/close', [LearningAssignmentController::class, 'close'])->whereNumber('id');
            Route::get('assignments/{id}/submissions', [LearningAssignmentController::class, 'submissions'])->whereNumber('id');
            Route::get('submissions/{id}/history', [LearningAssignmentController::class, 'history'])->whereNumber('id');
            Route::post('submissions/{id}/grade', [LearningAssignmentController::class, 'grade'])->whereNumber('id');
        });
        Route::middleware('perm:learn.participate')->group(function () {
            Route::put('assignments/{id}/draft', [LearningAssignmentController::class, 'saveDraft'])->whereNumber('id');
            Route::post('assignments/{id}/submit', [LearningAssignmentController::class, 'submit'])->whereNumber('id');
        });

        // --- attendance
        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/students/{studentId}/summary', [AttendanceController::class, 'summary'])->whereNumber('studentId');
        Route::middleware('perm:attendance.take')->group(function () {
            Route::put('sessions/{sessionId}/attendance', [AttendanceController::class, 'recordForSession'])->whereNumber('sessionId');
            Route::post('attendance/lesson', [AttendanceController::class, 'recordForEntry']);
        });

        // --- live classes
        Route::get('media/status', [SessionController::class, 'media']);
        Route::get('sessions', [SessionController::class, 'index']);
        Route::get('sessions/report', [SessionController::class, 'report'])->middleware('perm:sessions.monitor');
        Route::get('sessions/{id}', [SessionController::class, 'show'])->whereNumber('id');
        Route::post('sessions/{id}/join', [SessionController::class, 'join'])->whereNumber('id')->middleware('throttle:60,1');
        Route::post('sessions/{id}/leave', [SessionController::class, 'leave'])->whereNumber('id');
        Route::put('sessions/{id}/hand', [SessionController::class, 'hand'])->whereNumber('id');
        Route::post('sessions/{id}/issues', [SessionController::class, 'issue'])->whereNumber('id');
        Route::get('sessions/{id}/whiteboard', [SessionController::class, 'whiteboardIndex'])->whereNumber('id');
        Route::middleware('perm:sessions.host')->group(function () {
            Route::post('sessions', [SessionController::class, 'storeExtra']);
            Route::post('sessions/start-lesson', [SessionController::class, 'startForEntry']);
            Route::post('sessions/{id}/start', [SessionController::class, 'start'])->whereNumber('id');
            Route::post('sessions/{id}/end', [SessionController::class, 'end'])->whereNumber('id');
            Route::put('sessions/{id}/participants/{userId}/mute', [SessionController::class, 'mute'])->whereNumber(['id', 'userId']);
            Route::delete('sessions/{id}/participants/{userId}', [SessionController::class, 'kick'])->whereNumber(['id', 'userId']);
            Route::post('sessions/{id}/whiteboard', [SessionController::class, 'whiteboardStore'])->whereNumber('id');
            Route::put('sessions/{id}/recording', [SessionController::class, 'recording'])->whereNumber('id');
        });

        // --- messaging
        Route::middleware('perm:messaging.use')->group(function () {
            Route::get('conversations', [MessagingController::class, 'index']);
            Route::get('contacts', [MessagingController::class, 'contacts']);
            Route::post('conversations/direct', [MessagingController::class, 'direct']);
            Route::get('conversations/{id}/participants', [MessagingController::class, 'participants'])->whereNumber('id');
            Route::get('conversations/{id}/messages', [MessagingController::class, 'messages'])->whereNumber('id');
            Route::post('conversations/{id}/messages', [MessagingController::class, 'send'])->whereNumber('id')->middleware('throttle:40,1');
            Route::post('messages/{id}/report', [MessagingController::class, 'report'])->whereNumber('id');
            Route::put('conversations/{id}/lock', [MessagingController::class, 'lock'])->whereNumber('id');
        });
        Route::middleware('perm:messaging.moderate')->group(function () {
            Route::get('moderation/reports', [MessagingController::class, 'reports']);
            Route::get('moderation/reports/{id}', [MessagingController::class, 'reportShow'])->whereNumber('id');
            Route::post('moderation/reports/{id}/resolve', [MessagingController::class, 'reportResolve'])->whereNumber('id');
        });

        // --- question bank / exams
        Route::middleware('perm:exams.manage')->group(function () {
            Route::get('questions', [QuestionController::class, 'index']);
            Route::post('questions', [QuestionController::class, 'store']);
            Route::patch('questions/{id}', [QuestionController::class, 'update'])->whereNumber('id');
            Route::delete('questions/{id}', [QuestionController::class, 'destroy'])->whereNumber('id');
            Route::post('exams', [ExamController::class, 'store']);
            Route::post('exams/{id}/publish', [ExamController::class, 'publish'])->whereNumber('id');
            Route::post('exams/{id}/release', [ExamController::class, 'release'])->whereNumber('id');
            Route::get('exams/{id}/analysis', [ExamController::class, 'analysis'])->whereNumber('id');
            Route::get('exams/{id}/attempts', [ExamController::class, 'attempts'])->whereNumber('id');
            Route::get('exam-attempts/{id}/detail', [ExamController::class, 'attemptDetail'])->whereNumber('id');
            Route::put('exam-answers/{id}/grade', [ExamController::class, 'gradeAnswer'])->whereNumber('id');
        });
        Route::get('exams', [ExamController::class, 'index']);
        Route::get('exams/{id}/my-result', [ExamController::class, 'myResult'])->whereNumber('id');
        Route::middleware('perm:learn.participate')->group(function () {
            Route::post('exams/{id}/start', [ExamController::class, 'start'])->whereNumber('id');
            Route::get('exam-attempts/{id}', [ExamController::class, 'attempt'])->whereNumber('id');
            Route::put('exam-attempts/{id}/answers', [ExamController::class, 'saveAnswers'])->whereNumber('id')->middleware('throttle:240,1');
            Route::post('exam-attempts/{id}/submit', [ExamController::class, 'submit'])->whereNumber('id');
        });

        // --- grades / report cards
        Route::get('grades', [GradeController::class, 'index']);
        Route::get('grades/students/{studentId}/summary', [GradeController::class, 'studentSummary'])->whereNumber('studentId');
        Route::get('grading/rules', [GradeController::class, 'rules']);
        Route::middleware('perm:grades.enter,grades.approve')->group(function () {
            Route::post('grades', [GradeController::class, 'store']);
            Route::patch('grades/{id}', [GradeController::class, 'update'])->whereNumber('id');
            Route::get('grades/{id}/history', [GradeController::class, 'history'])->whereNumber('id');
        });
        Route::middleware('perm:grades.approve')->group(function () {
            Route::post('grades/{id}/approve', [GradeController::class, 'approve'])->whereNumber('id');
            Route::post('grades/approve-bulk', [GradeController::class, 'approveBulk']);
            Route::put('grading/rules', [GradeController::class, 'updateRules']);
        });
        Route::get('report-cards', [ReportCardController::class, 'index']);
        Route::get('report-cards/{id}', [ReportCardController::class, 'show'])->whereNumber('id');
        Route::get('report-cards/{id}/pdf', [ReportCardController::class, 'pdf'])->whereNumber('id');
        Route::middleware('perm:reportcards.manage')->group(function () {
            Route::get('report-card-templates', [ReportCardController::class, 'templates']);
            Route::post('report-card-templates', [ReportCardController::class, 'storeTemplate']);
            Route::patch('report-card-templates/{id}', [ReportCardController::class, 'updateTemplate'])->whereNumber('id');
            Route::post('report-cards/generate', [ReportCardController::class, 'generate']);
            Route::post('report-cards/{id}/issue', [ReportCardController::class, 'issue'])->whereNumber('id');
            Route::post('report-cards/issue-section', [ReportCardController::class, 'issueSection']);
            Route::post('report-cards/{id}/revoke', [ReportCardController::class, 'revoke'])->whereNumber('id');
        });

        // --- AI
        Route::get('ai/status', [AiController::class, 'status']);
        Route::delete('ai/my-data', [AiController::class, 'eraseMine']);
        Route::post('ai/ask', [AiController::class, 'ask'])->middleware('throttle:20,1');
        Route::post('ai/transcribe', [AiController::class, 'transcribe'])->middleware('throttle:10,1');
        Route::post('ai/speak', [AiController::class, 'speak'])->middleware('throttle:10,1');
        Route::middleware(['perm:ai.use_teacher', 'throttle:20,1'])->prefix('ai/teacher')->group(function () {
            Route::post('lesson-plan', [AiController::class, 'lessonPlan']);
            Route::post('questions', [AiController::class, 'generateQuestions']);
            Route::post('rubric', [AiController::class, 'rubric']);
            Route::post('answers/{answerId}/grade-suggestion', [AiController::class, 'gradeSuggestion'])->whereNumber('answerId');
            Route::post('remedial', [AiController::class, 'remedial']);
        });
        Route::middleware('perm:ai.use_admin')->group(function () {
            Route::post('ai/admin/summary', [AiController::class, 'adminSummary'])->middleware('throttle:10,1');
            Route::get('ai/usage', [AiController::class, 'usage']);
        });

        // --- analytics (numbers computed from data, no AI)
        Route::middleware('perm:attendance.view_all')->group(function () {
            Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
            Route::get('analytics/attention', [AnalyticsController::class, 'attention']);
            Route::get('analytics/teachers', [AnalyticsController::class, 'teachers']);
        });
        Route::get('analytics/students/{studentId}/progress', [AnalyticsController::class, 'studentProgress'])->whereNumber('studentId');
    });
});
