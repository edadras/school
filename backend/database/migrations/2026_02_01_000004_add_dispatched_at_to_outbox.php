<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_outbox', function (Blueprint $t) {
            $t->timestamp('dispatched_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notifications_outbox', function (Blueprint $t) {
            $t->dropColumn('dispatched_at');
        });
    }
};
