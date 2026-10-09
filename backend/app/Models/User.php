<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // platform_role / status are deliberately NOT fillable (no mass-assignment escalation).
    protected $fillable = ['name', 'email', 'phone', 'national_code', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'password' => 'hashed'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SchoolUserMembership::class);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platform_role === 'super_admin';
    }

    public function hasPermissionInSchool(string $permission, int $schoolId): bool
    {
        return RolePermissionCache::userHas($this->id, $schoolId, $permission);
    }
}
