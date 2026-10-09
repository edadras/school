<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ExamAttempt extends Model
{
    use BelongsToSchool;

    protected $table = 'exam_attempts';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'deadline_at' => 'datetime', 'submitted_at' => 'datetime', 'question_order' => 'array', 'option_orders' => 'array', 'needs_manual' => 'boolean', 'auto_score' => 'float', 'manual_score' => 'float', 'total_score' => 'float'];
    }
}
