<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {name=Admin}';

    protected $description = 'Create a super admin (password is prompted; never read from files or env)';

    public function handle(): int
    {
        $password = $this->secret('Password (min 12 chars)');
        if (strlen((string) $password) < 12) {
            $this->error('Password too short.');

            return self::FAILURE;
        }
        $user = User::firstOrNew(['email' => $this->argument('email')]);
        $user->fill(['name' => $this->argument('name'), 'password' => $password]);
        $user->forceFill(['platform_role' => 'super_admin'])->save();
        $this->info("Super admin ready: {$user->email}");

        return self::SUCCESS;
    }
}
