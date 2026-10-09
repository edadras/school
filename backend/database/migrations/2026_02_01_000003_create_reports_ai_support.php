<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('header', 255)->nullable();
            $t->text('footer')->nullable();
            $t->json('signatures');                                // ["مدیر","معلم"]
            $t->json('options')->nullable();                       // show_attendance, show_remarks ...
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });

        Schema::create('report_cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->cascadeOnDelete();
            $t->foreignId('template_id')->nullable()->constrained('report_card_templates')->nullOnDelete();
            $t->string('status', 10)->default('draft');            // draft|issued|revoked
            $t->decimal('average', 6, 2)->nullable();
            $t->string('result', 12)->nullable();                  // passed|failed|makeup
            $t->text('remarks')->nullable();
            $t->json('attendance_summary')->nullable();
            $t->json('formula_snapshot')->nullable();              // exactly what rules produced this card
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'term_id']);
            $t->index(['school_id', 'term_id', 'status']);
        });

        Schema::create('report_card_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->decimal('score', 6, 2)->nullable();
            $t->decimal('coefficient', 4, 2)->default(1);
            $t->string('remarks', 255)->nullable();
            $t->timestamps();
            $t->unique(['report_card_id', 'subject_id']);
        });

        // Discipline, encouragement, counselling, strengths/needs. Visible per policy only.
        Schema::create('student_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $t->string('kind', 12);                                // discipline|praise|counselling|strength|need
            $t->string('title', 150);
            $t->text('body')->nullable();
            $t->boolean('visible_to_guardian')->default(false);
            $t->timestamps();
            $t->index(['school_id', 'student_id', 'kind']);
        });

        Schema::create('ai_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('purpose', 30);
            $t->string('provider', 30);
            $t->string('model', 60)->nullable();
            $t->string('status', 10);                              // ok|error|blocked|limited
            $t->string('prompt_hash', 64)->nullable();             // no raw prompts stored
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedBigInteger('cost_micro')->default(0);      // 1e-6 currency units
            $t->unsignedInteger('latency_ms')->default(0);
            $t->string('error', 255)->nullable();
            $t->json('sources')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['school_id', 'created_at']);
            $t->index(['user_id', 'created_at']);
        });

        Schema::create('ai_usage_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->date('on_date');
            $t->string('purpose', 30);
            $t->unsignedInteger('requests')->default(0);
            $t->unsignedBigInteger('input_tokens')->default(0);
            $t->unsignedBigInteger('output_tokens')->default(0);
            $t->unsignedBigInteger('cost_micro')->default(0);
            $t->timestamps();
            $t->unique(['school_id', 'on_date', 'purpose']);
        });

        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('subject', 150);
            $t->string('category', 20)->default('technical');      // technical|billing|abuse|meeting|other
            $t->string('priority', 8)->default('normal');
            $t->string('status', 10)->default('open')->index();     // open|pending|resolved|closed
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('support_ticket_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->boolean('internal')->default(false);
            $t->timestamps();
        });

        Schema::create('platform_announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $t->string('title', 150);
            $t->text('body');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        Schema::create('import_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);
            $t->string('status', 12)->default('done');
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('created')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->json('errors')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['import_jobs', 'platform_settings', 'platform_announcements', 'support_ticket_messages', 'support_tickets',
            'ai_usage_records', 'ai_requests', 'student_notes', 'report_card_items', 'report_cards', 'report_card_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
