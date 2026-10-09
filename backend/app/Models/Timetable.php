<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Timetable extends Model
{
    use BelongsToSchool;

    protected $table = 'timetables';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['working_days' => 'array', 'activated_at' => 'datetime'];
    }

    public function periods()
    {
        return $this->hasMany(TimetablePeriod::class)->orderBy('position');
    }

    public function entries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

}
