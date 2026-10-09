<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    use BelongsToSchool;

    protected $table = 'import_jobs';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }
}
