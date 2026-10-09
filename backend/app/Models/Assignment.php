<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    use BelongsToSchool;

    protected $table = 'assignments';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime', 'due_at' => 'datetime', 'rubric' => 'array', 'answer_types' => 'array', 'allow_draft' => 'boolean', 'allow_late' => 'boolean', 'allow_resubmit' => 'boolean', 'max_score' => 'float'];
    }
}
