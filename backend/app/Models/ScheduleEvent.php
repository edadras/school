<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ScheduleEvent extends Model
{
    use BelongsToSchool;

    protected $table = 'schedule_events';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['on_date' => 'date:Y-m-d', 'fires_at' => 'datetime', 'processed_at' => 'datetime'];
    }

}
