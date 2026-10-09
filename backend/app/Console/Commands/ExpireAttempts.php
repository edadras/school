<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Modules\Exams\ExamService;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Console\Command;

class ExpireAttempts extends Command
{
    protected $signature = 'exams:expire-attempts';

    protected $description = 'Auto-submit exam attempts whose server deadline passed (idempotent)';

    public function handle(CurrentSchool $current, ExamService $svc): int
    {
        $n = 0;
        School::where('status', 'active')->each(fn (School $s) => $n += $current->run($s, fn () => $svc->expireOverdue()));
        $this->info("expired: $n");

        return self::SUCCESS;
    }
}
