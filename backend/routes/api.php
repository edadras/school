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
use Illuminate\Support\Facades\Route;

// All routes are served under /api/v1 (see bootstrap/app.php).

// ---- Public -----------------------------------------------------------------
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
Route::post('schools/register', [SchoolRegistrationController::class, 'register'])->middleware('throttle:school-register');

// ---- Authenticated (no school context) -------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // School owner follows up on / resubmits their own registration while still "pending".
    Route::get('schools/my-request', [SchoolRegistrationController::class, 'myRequest']);
    Route::post('schools/my-request/resubmit', [SchoolRegistrationController::class, 'resubmit']);

    // ---- Platform (super admin) ------------------------------------------
    Route::prefix('platform')->middleware('platform.role:super_admin')->group(function () {
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
        Route::get('me/children', [GuardianController::class, 'myChildren']);

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
    });
});
