<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ExamQuestion extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected $table = 'exam_questions';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['points' => 'float'];
    }
}
