<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Not using BelongsToSchool on purpose: SettingsRepository always filters by the resolved tenant explicitly. */
class SchoolSetting extends Model
{
    protected $table = 'school_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
