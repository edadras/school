<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ReportCard extends Model
{
    use BelongsToSchool;

    protected $table = 'report_cards';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    protected function casts(): array
    {
        return ['attendance_summary' => 'array', 'formula_snapshot' => 'array', 'issued_at' => 'datetime', 'average' => 'float'];
    }

    public function items()
    {
        return $this->hasMany(ReportCardItem::class);
    }
}
