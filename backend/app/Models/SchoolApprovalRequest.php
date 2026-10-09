<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolApprovalRequest extends Model
{
    protected $table = 'school_approval_requests';

    protected $guarded = ['id'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'documents' => 'array', 'decided_at' => 'datetime'];
    }
}
