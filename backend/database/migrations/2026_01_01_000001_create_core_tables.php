<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core: users, tenants (schools), RBAC, memberships, audit.
 * Portable across MySQL 8 (production) and SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable()->unique();
            $t->string('phone', 20)->nullable()->unique();
            $t->string('national_code', 20)->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            // Platform-level role (not tied to a school): super_admin | support
            $t->string('platform_role', 20)->nullable()->index();
            $t->string('status', 20)->default('active')->index(); // active|disabled
            $t->string('locale', 8)->default('fa');
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('schools', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();         // public unique identifier / slug
            $t->string('name');
            $t->string('logo_path')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('address', 500)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('timezone', 64)->default('Asia/Tehran');
            $t->string('calendar', 16)->default('jalali'); // display calendar
            $t->string('locale', 8)->default('fa');
            // pending|needs_changes|active|rejected|suspended
            $t->string('status', 20)->default('pending')->index();
            $t->string('status_reason', 1000)->nullable();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('school_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('key', 100);
            $t->json('value')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'key']);
        });

        Schema::create('school_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('plan', 40)->default('free');
            $t->unsignedInteger('max_students')->default(100);
            $t->unsignedInteger('max_teachers')->default(20);
            $t->unsignedInteger('max_live_sessions')->default(2);   // concurrent live classes
            $t->unsignedInteger('max_storage_mb')->default(1024);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->timestamps();
            $t->index(['school_id', 'status']);
        });

        Schema::create('school_approval_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('payload')->nullable();      // org form snapshot
            $t->json('documents')->nullable();    // private-storage file keys
            $t->string('status', 20)->default('pending')->index(); // pending|approved|rejected|needs_changes|superseded
            $t->string('decision_note', 1000)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();      // school_admin, deputy, teacher, student, guardian, ...
            $t->string('name');
            $t->string('scope', 10)->default('school'); // school|platform
            $t->boolean('is_system')->default(true);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();      // e.g. academics.manage
            $t->string('description')->nullable();
            $t->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('school_user_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['school_id', 'user_id', 'role_id']);
            $t->index(['user_id', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 80)->index();
            $t->string('subject_type', 80)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['school_id', 'created_at']);
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'school_user_memberships', 'role_permissions', 'permissions', 'roles',
            'school_approval_requests', 'school_subscriptions', 'school_settings', 'schools',
            'password_reset_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
