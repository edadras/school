<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class GradingRubric extends Model
{
    use BelongsToSchool;

    protected $table = 'grading_rubrics';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
