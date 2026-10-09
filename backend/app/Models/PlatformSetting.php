<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';
}
