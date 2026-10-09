<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class GradeRecord extends Model
{
    use BelongsToSchool;

    protected $table = 'grade_records';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'score' => 'float', 'max_score' => 'float', 'weight' => 'float'];
    }
}
