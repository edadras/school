<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class LessonSession extends Model
{
    use BelongsToSchool;

    protected $table = 'lesson_sessions';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['on_date' => 'date:Y-m-d', 'scheduled_start' => 'datetime', 'scheduled_end' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'recording_enabled' => 'boolean'];
    }
}
