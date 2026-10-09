<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_recordings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('provider_id', 80)->nullable();                // egress id
            $t->string('status', 12)->default('starting');           // starting|recording|stopping|ready|failed
            $t->string('object_key', 255);                            // where the egress writes it in the bucket
            $t->foreignId('file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $t->unsignedBigInteger('size')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->string('error', 255)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'lesson_session_id']);
            $t->unique('provider_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_recordings');
    }
};
