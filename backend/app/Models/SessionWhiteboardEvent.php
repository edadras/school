<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class SessionWhiteboardEvent extends Model
{
    use BelongsToSchool;

    protected $table = 'session_whiteboard_events';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
