<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_two_factor', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('secret');                                   // encrypted with APP_KEY
            $t->timestamp('confirmed_at')->nullable();
            $t->json('recovery_codes')->nullable();               // sha256 hashes; each usable once
            $t->unsignedBigInteger('last_used_step')->nullable();  // replay protection for TOTP
            $t->timestamps();
        });

        // Result of the upload malware scan (clean|infected|skipped|error) kept with the file record.
        Schema::table('stored_files', function (Blueprint $t) {
            $t->string('scan_status', 12)->default('skipped')->after('sha256');
            $t->string('scan_detail', 255)->nullable()->after('scan_status');
        });
    }

    public function down(): void
    {
        Schema::table('stored_files', fn (Blueprint $t) => $t->dropColumn(['scan_status', 'scan_detail']));
        Schema::dropIfExists('user_two_factor');
    }
};
