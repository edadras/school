<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic structure + weekly timetable + bell engine.
 * Every tenant table carries school_id and a school-leading index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('title', 60);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->boolean('is_current')->default(false);
            $t->timestamps();
            $t->unique(['school_id', 'title']);
        });

        Schema::create('terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $t->string('title', 60);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->timestamps();
            $t->index(['school_id', 'academic_year_id']);
        });

        Schema::create('grades', function (Blueprint $t) {  // پایه
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('stage', 40)->nullable();           // مقطع
            $t->string('name', 60);
            $t->unsignedTinyInteger('level')->default(1);  // ordering / promotion path
            $t->timestamps();
            $t->unique(['school_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 100);
            $t->string('code', 30)->nullable();
            $t->decimal('coefficient', 4, 2)->default(1);  // used by GPA formula
            $t->timestamps();
            $t->index(['school_id', 'grade_id']);
        });

        Schema::create('sections', function (Blueprint $t) {  // کلاس
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('track', 60)->nullable();           // رشته
            $t->unsignedSmallInteger('capacity')->default(30);
            $t->timestamps();
            $t->unique(['school_id', 'academic_year_id', 'grade_id', 'name']);
        });

        Schema::create('teachers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('personnel_code', 40)->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['school_id', 'user_id']);
        });

        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('first_name', 80);
            $t->string('last_name', 80);
            $t->string('student_code', 40);
            $t->date('birth_date')->nullable();
            $t->string('status', 20)->default('active'); // active|transferred|graduated|archived
            $t->json('custom_fields')->nullable();       // school-defined extra fields
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['school_id', 'student_code']);
        });

        Schema::create('guardians', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 20)->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'user_id']);
        });

        Schema::create('student_guardians', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $t->string('relation', 30)->default('parent');
            // Parent sees the child only after explicit school approval.
            $t->string('status', 20)->default('pending'); // pending|approved|revoked
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'guardian_id']);
            $t->index(['school_id', 'guardian_id', 'status']);
        });

        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('active'); // active|transferred|promoted|withdrawn
            $t->date('enrolled_on')->nullable();
            $t->date('ended_on')->nullable();
            $t->timestamps();
            // A student has at most one enrollment per academic year (history kept via status).
            $t->unique(['student_id', 'academic_year_id']);
            $t->index(['school_id', 'section_id', 'status']);
        });

        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('weekly_hours')->default(2);
            $t->timestamps();
            $t->unique(['section_id', 'subject_id']);       // one owner teacher per subject/section
            $t->index(['school_id', 'teacher_id']);
        });

        // ---- Scheduling ------------------------------------------------------

        Schema::create('timetables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $t->string('title', 80);
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 20)->default('draft');     // draft|active|archived (old versions retained)
            $t->json('working_days');                        // [0..6], 0 = Saturday (Iran default)
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'status']);
        });

        Schema::create('timetable_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('position');
            $t->string('kind', 12);                          // lesson|break|prep
            $t->string('title', 60);
            $t->time('starts_at');
            $t->time('ends_at');
            $t->timestamps();
            $t->unique(['timetable_id', 'position']);
        });

        Schema::create('timetable_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('timetable_periods')->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            // DB-level backstops against double booking (service validates first for friendly errors)
            $t->unique(['timetable_id', 'weekday', 'period_id', 'section_id'], 'tt_entry_section_slot');
            $t->unique(['timetable_id', 'weekday', 'period_id', 'teacher_id'], 'tt_entry_teacher_slot');
            $t->index(['school_id', 'teacher_id']);
        });

        Schema::create('school_calendar_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);                          // holiday|exceptional|meeting|event
            $t->string('title', 120);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->boolean('cancels_classes')->default(false);
            $t->timestamps();
            $t->index(['school_id', 'starts_on', 'ends_on']);
        });

        Schema::create('substitutions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('timetable_entry_id')->constrained('timetable_entries')->cascadeOnDelete();
            $t->date('on_date');
            $t->foreignId('substitute_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $t->string('status', 12)->default('substitute'); // substitute|cancelled
            $t->string('reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['timetable_entry_id', 'on_date']);
        });

        // Bell engine: one row per (school, date, period, kind). The unique key is what makes
        // job re-runs and scheduler overlap idempotent.
        Schema::create('schedule_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $t->foreignId('period_id')->constrained('timetable_periods')->cascadeOnDelete();
            $t->date('on_date');
            $t->string('event', 12);                         // start|end
            $t->timestamp('fires_at')->index();              // UTC instant
            $t->timestamp('processed_at')->nullable();
            $t->unsignedInteger('notified_count')->default(0);
            $t->timestamps();
            $t->unique(['school_id', 'on_date', 'period_id', 'event'], 'schedule_event_once');
        });

        Schema::create('notifications_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);
            $t->string('dedupe_key', 120);                   // idempotency key per user+event
            $t->string('title');
            $t->string('body', 500)->nullable();
            $t->json('data')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'dedupe_key']);
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        foreach (['notifications_outbox', 'schedule_events', 'substitutions', 'school_calendar_events',
            'timetable_entries', 'timetable_periods', 'timetables', 'teacher_assignments', 'enrollments',
            'student_guardians', 'guardians', 'students', 'teachers', 'sections', 'subjects', 'grades',
            'terms', 'academic_years'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
