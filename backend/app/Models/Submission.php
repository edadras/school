<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use BelongsToSchool;

    protected $table = 'submissions';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['drawing' => 'array', 'math' => 'array', 'rubric_scores' => 'array', 'viewed_at' => 'datetime', 'draft_saved_at' => 'datetime', 'submitted_at' => 'datetime', 'graded_at' => 'datetime', 'is_late' => 'boolean', 'score' => 'float'];
    }

    public function files()
    {
        return $this->hasMany(SubmissionFile::class);
    }
}
