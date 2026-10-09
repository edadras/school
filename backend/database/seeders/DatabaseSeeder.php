<?php

namespace Database\Seeders;

use App\Modules\Auth\RbacSync;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        RbacSync::run(); // roles + permissions only; safe in production

        if (app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
