<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $table = 'ai_requests';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['sources' => 'array'];
    }
}
