<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guardian → school: request a meeting / educational follow-up about their own child.
        Schema::create('meeting_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();   // null => school management
            $t->string('topic', 200);
            $t->text('details')->nullable();
            $t->timestamp('preferred_at')->nullable();
            $t->string('status', 10)->default('pending');                              // pending|accepted|declined|done
            $t->timestamp('scheduled_at')->nullable();
            $t->string('response_note', 500)->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_requests');
    }
};
