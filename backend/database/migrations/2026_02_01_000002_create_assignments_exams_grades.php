<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 150);
            $t->text('description')->nullable();
            $t->timestamp('publish_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->decimal('max_score', 6, 2)->default(20);
            $t->json('rubric')->nullable();               // [{title, max}]
            $t->json('answer_types');                     // text|file|audio|video|drawing|math
            $t->boolean('allow_draft')->default(true);
            $t->boolean('allow_late')->default(true);
            $t->boolean('allow_resubmit')->default(true);
            $t->string('status', 10)->default('draft');   // draft|published|closed
            $t->timestamps();
            $t->index(['school_id', 'section_id', 'status']);
        });

        Schema::create('assignment_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('file_id')->constrained('stored_files')->cascadeOnDelete();
            $t->timestamps();
        });

        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            // viewed|in_progress|submitted|late|under_review|needs_revision|finalized
            $t->string('status', 16)->default('viewed');
            $t->unsignedSmallInteger('attempt')->default(0);
            $t->text('text_answer')->nullable();
            $t->json('drawing')->nullable();
            $t->json('math')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('draft_saved_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->boolean('is_late')->default(false);
            $t->decimal('score', 6, 2)->nullable();
            $t->json('rubric_scores')->nullable();
            $t->text('feedback')->nullable();
            $t->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('graded_at')->nullable();
            $t->timestamps();
            $t->unique(['assignment_id', 'student_id']);
            $t->index(['school_id', 'status']);
        });

        Schema::create('submission_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('file_id')->constrained('stored_files')->cascadeOnDelete();
            $t->string('kind', 10)->default('file');      // file|audio|video|image
            $t->timestamps();
        });

        // Immutable snapshot at each submission: "سوابق ارسال‌های قبلی".
        Schema::create('submission_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('attempt');
            $t->json('snapshot');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['submission_id', 'attempt']);
        });

        Schema::create('questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->string('topic', 100)->nullable();
            $t->unsignedTinyInteger('difficulty')->default(2);   // 1..5
            $t->string('type', 10);                               // mcq|tf|fill|short|essay
            $t->text('body');
            $t->foreignId('media_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $t->json('accepted_answers')->nullable();             // fill/short: accepted strings; tf: [true|false]
            $t->text('rubric')->nullable();                       // essay marking guide / answer key
            $t->decimal('points', 5, 2)->default(1);
            $t->timestamps();
            $t->index(['school_id', 'subject_id', 'grade_id', 'difficulty']);
        });

        Schema::create('question_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('question_id')->constrained()->cascadeOnDelete();
            $t->string('text', 500);
            $t->boolean('is_correct')->default(false);
            $t->unsignedTinyInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 150);
            $t->timestamp('start_at');
            $t->timestamp('end_at');
            $t->unsignedSmallInteger('duration_minutes');
            $t->unsignedTinyInteger('max_attempts')->default(1);
            $t->boolean('shuffle_questions')->default(false);
            $t->boolean('shuffle_options')->default(false);
            // never|after_end|manual|immediately
            $t->string('show_score', 12)->default('manual');
            $t->string('show_answers', 12)->default('never');
            $t->string('status', 10)->default('draft');           // draft|published|closed
            $t->timestamp('results_released_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'section_id', 'status']);
        });

        Schema::create('exam_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $t->foreignId('question_id')->constrained()->cascadeOnDelete();
            $t->decimal('points', 5, 2);
            $t->unsignedSmallInteger('position')->default(0);
            $t->unique(['exam_id', 'question_id']);
        });

        Schema::create('exam_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('attempt_no');
            $t->timestamp('started_at');
            $t->timestamp('deadline_at');                          // min(start+duration, exam end): server authority
            $t->timestamp('submitted_at')->nullable();
            $t->string('status', 12)->default('in_progress');      // in_progress|submitted|graded
            $t->json('question_order');
            $t->json('option_orders')->nullable();
            $t->decimal('auto_score', 7, 2)->default(0);
            $t->decimal('manual_score', 7, 2)->default(0);
            $t->decimal('total_score', 7, 2)->nullable();
            $t->boolean('needs_manual')->default(false);
            $t->timestamps();
            $t->unique(['exam_id', 'student_id', 'attempt_no']);
        });

        Schema::create('exam_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('question_id')->constrained()->cascadeOnDelete();
            $t->json('answer')->nullable();                        // {option_ids:[]} | {text:""} | {value:bool}
            $t->decimal('auto_score', 5, 2)->nullable();
            $t->decimal('manual_score', 5, 2)->nullable();
            $t->text('feedback')->nullable();
            $t->json('ai_suggestion')->nullable();                 // suggestion only; never a score of record
            $t->timestamp('saved_at')->nullable();
            $t->timestamps();
            $t->unique(['exam_attempt_id', 'question_id']);
        });

        Schema::create('grading_rubrics', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->string('title', 120);
            $t->json('criteria');                                  // [{title,max,description}]
            $t->timestamps();
        });

        Schema::create('grade_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12);                                // exam|assignment|classwork|oral|project|final
            $t->string('source_type', 20)->nullable();             // exam_attempt|submission
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('title', 150);
            $t->decimal('score', 6, 2);
            $t->decimal('max_score', 6, 2)->default(20);
            $t->decimal('weight', 5, 2)->default(1);
            $t->string('status', 10)->default('draft');           // draft|approved
            $t->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['source_type', 'source_id', 'student_id'], 'grade_record_source');
            $t->index(['school_id', 'student_id', 'term_id']);
            $t->index(['school_id', 'section_id', 'subject_id', 'term_id']);
        });

        Schema::create('grade_record_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('grade_record_id')->constrained()->cascadeOnDelete();
            $t->decimal('old_score', 6, 2)->nullable();
            $t->decimal('new_score', 6, 2)->nullable();
            $t->string('old_status', 10)->nullable();
            $t->string('new_status', 10)->nullable();
            $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reason', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['grade_record_history', 'grade_records', 'grading_rubrics', 'exam_answers', 'exam_attempts', 'exam_questions', 'exams',
            'question_options', 'questions', 'submission_versions', 'submission_files', 'submissions', 'assignment_attachments', 'assignments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
