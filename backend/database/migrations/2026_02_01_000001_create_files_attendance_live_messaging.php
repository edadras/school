<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private files only. `path` is a key on a non-public disk; access always goes through FileAccess + signed URL.
        Schema::create('stored_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('disk', 30);
            $t->string('path', 255);
            $t->string('original_name', 255);
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64)->nullable();
            $t->string('context_type', 40)->nullable();      // material|assignment|submission|message|exam_question|logo
            $t->unsignedBigInteger('context_id')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'context_type', 'context_id']);
        });

        Schema::create('lesson_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('timetable_entry_id')->nullable()->constrained('timetable_entries')->nullOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();   // host
            $t->date('on_date');
            $t->string('kind', 12)->default('regular');                     // regular|makeup|substitute
            $t->string('title', 150);
            $t->timestamp('scheduled_start');
            $t->timestamp('scheduled_end');
            // scheduled|live|ended|not_held|technical_issue
            $t->string('status', 20)->default('scheduled')->index();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->string('room_name', 64)->unique();
            $t->string('provider', 20)->nullable();
            $t->boolean('recording_enabled')->default(false);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['timetable_entry_id', 'on_date']);
            $t->index(['school_id', 'section_id', 'on_date']);
        });

        Schema::create('session_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 12);                    // host|participant
            $t->timestamp('joined_at');
            $t->timestamp('left_at')->nullable();
            $t->string('client', 60)->nullable();
            $t->timestamps();
            $t->index(['lesson_session_id', 'user_id']);
        });

        Schema::create('session_issues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 30);                    // audio|video|connection|teacher_absent|other
            $t->string('note', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('session_whiteboard_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->json('payload');                       // stroke / shape / text / clear / page
            $t->timestamps();
            $t->index(['lesson_session_id', 'id']);
        });

        Schema::create('session_hands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('raised_at');
            $t->timestamp('lowered_at')->nullable();
            $t->index(['lesson_session_id', 'lowered_at']);
        });

        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lesson_session_id')->nullable()->constrained()->nullOnDelete();
            $t->string('lesson_key', 40);              // "e{entry}:{date}" or "s{session}"
            $t->date('on_date');
            $t->string('status', 10);                  // present|late|absent|excused
            $t->unsignedSmallInteger('minutes_late')->default(0);
            $t->string('source', 10)->default('auto'); // auto (join events) | teacher
            $t->boolean('confirmed')->default(false);
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'lesson_key']);
            $t->index(['school_id', 'section_id', 'on_date']);
        });

        Schema::create('learning_materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->string('kind', 10);                    // file|link|text
            $t->text('body')->nullable();              // text content or URL
            $t->foreignId('file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $t->boolean('ai_indexable')->default(true); // may be used as an AI source
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'section_id', 'subject_id']);
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $t->string('title', 150);
            $t->text('body');
            $t->string('audience_type', 10);           // school|grade|section|student(family)
            $t->unsignedBigInteger('audience_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'published_at']);
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12);                    // class|direct|assignment|official|session
            $t->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('assignment_id')->nullable();
            $t->unsignedBigInteger('lesson_session_id')->nullable();
            $t->string('title', 150)->nullable();
            $t->string('direct_key', 40)->nullable();  // "min:max" user ids, makes direct chats unique
            $t->boolean('is_locked')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['school_id', 'direct_key']);
            $t->index(['school_id', 'type', 'section_id']);
        });

        Schema::create('conversation_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 10)->default('member'); // member|owner
            $t->unsignedBigInteger('last_read_message_id')->default(0);
            $t->timestamps();
            $t->unique(['conversation_id', 'user_id']);
            $t->index(['user_id', 'school_id']);
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $t->string('kind', 10)->default('text');   // text|file|audio|official
            $t->text('body')->nullable();
            $t->string('client_id', 64)->nullable();   // idempotency key for retried sends
            $t->timestamps();
            $t->softDeletes();
            $t->index(['conversation_id', 'id']);
            $t->unique(['conversation_id', 'user_id', 'client_id']);
        });

        Schema::create('message_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('file_id')->constrained('stored_files')->cascadeOnDelete();
            $t->timestamps();
        });

        Schema::create('message_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 255);
            $t->string('status', 12)->default('open'); // open|actioned|dismissed
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('resolution_note', 255)->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'reporter_id']);
        });

        // Notifications: per-user preferences + device tokens + per-channel delivery log.
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);                    // e.g. bell.lesson_start or '*'
            $t->string('channel', 10);                 // in_app|push|email
            $t->boolean('enabled')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'type', 'channel']);
        });

        Schema::create('device_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('platform', 10);                // android|ios|web
            $t->string('token', 512);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'token']);
        });

        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notification_id')->constrained('notifications_outbox')->cascadeOnDelete();
            $t->string('channel', 10);
            $t->string('status', 12);                  // sent|failed|skipped|unconfigured
            $t->string('error', 255)->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'channel']); // a channel is attempted once per notification
        });

        // Time-limited support access to a school, granted BY that school's admin.
        Schema::create('support_access_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('support_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 255);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['support_user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        foreach (['support_access_grants', 'notification_deliveries', 'device_tokens', 'notification_preferences', 'message_reports',
            'message_attachments', 'messages', 'conversation_participants', 'conversations', 'announcements', 'learning_materials',
            'attendance_records', 'session_hands', 'session_whiteboard_events', 'session_issues', 'session_participants',
            'lesson_sessions', 'stored_files'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
