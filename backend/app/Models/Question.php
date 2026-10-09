<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use BelongsToSchool;

    protected $table = 'questions';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['accepted_answers' => 'array', 'points' => 'float'];
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }
}
