<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    use BelongsToSchool;

    protected $table = 'exams';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime', 'results_released_at' => 'datetime', 'shuffle_questions' => 'boolean', 'shuffle_options' => 'boolean'];
    }

    public function examQuestions()
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('position');
    }
}
