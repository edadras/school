<?php

namespace App\Console\Commands;

use App\Modules\Auth\RbacSync as Sync;
use Illuminate\Console\Command;

class RbacSync extends Command
{
    protected $signature = 'rbac:sync';

    protected $description = 'Sync roles and permissions from config/rbac.php into the database';

    public function handle(): int
    {
        Sync::run();
        $this->info('RBAC synced.');

        return self::SUCCESS;
    }
}
