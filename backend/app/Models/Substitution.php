<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Substitution extends Model
{
    use BelongsToSchool;

    protected $table = 'substitutions';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    public function entry()
    {
        return $this->belongsTo(TimetableEntry::class, 'timetable_entry_id');
    }

}
