<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Modules\Files\RetentionService;
use Illuminate\Console\Command;

class RetentionRun extends Command
{
    protected $signature = 'retention:run {--dry-run : only count what would be deleted}';

    protected $description = 'Apply each school\'s data-retention policy (idempotent; academic records and audit log are never deleted)';

    public function handle(RetentionService $svc): int
    {
        School::where('status', 'active')->each(function (School $s) use ($svc) {
            $r = $svc->run($s, (bool) $this->option('dry-run'));
            $this->line("school {$s->id}: ".json_encode($r));
        });

        return self::SUCCESS;
    }
}
