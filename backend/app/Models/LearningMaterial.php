<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class LearningMaterial extends Model
{
    use BelongsToSchool;

    protected $table = 'learning_materials';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'ai_indexable' => 'boolean'];
    }
}
