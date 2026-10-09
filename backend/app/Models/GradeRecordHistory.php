<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class GradeRecordHistory extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $table = 'grade_record_history';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];
}
