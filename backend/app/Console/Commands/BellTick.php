<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Modules\Scheduling\BellEngine;
use Illuminate\Console\Command;

class BellTick extends Command
{
    protected $signature = 'bell:tick';

    protected $description = 'Generate and fire due bell events for every active school (idempotent; run every minute)';

    public function handle(BellEngine $engine): int
    {
        $total = 0;
        School::where('status', 'active')->orderBy('id')->each(function (School $school) use ($engine, &$total) {
            try {
                $total += $engine->runForSchool($school);
            } catch (\Throwable $e) {
                // One school's failure must not stop the others.
                report($e);
                $this->error("school {$school->id}: {$e->getMessage()}");
            }
        });
        $this->info("fired events: $total");

        return self::SUCCESS;
    }
}
