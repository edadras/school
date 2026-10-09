<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTwoFactor extends Model
{
    protected $table = 'user_two_factor';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'recovery_codes' => 'array', 'confirmed_at' => 'datetime'];
    }
}
