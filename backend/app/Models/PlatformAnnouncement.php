<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAnnouncement extends Model
{
    protected $table = 'platform_announcements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
