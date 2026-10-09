<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ReportCardTemplate extends Model
{
    use BelongsToSchool;

    protected $table = 'report_card_templates';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['signatures' => 'array', 'options' => 'array', 'is_default' => 'boolean'];
    }
}
