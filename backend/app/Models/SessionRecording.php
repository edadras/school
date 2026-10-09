<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class SessionRecording extends Model
{
    use BelongsToSchool;

    protected $table = 'session_recordings';

    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
