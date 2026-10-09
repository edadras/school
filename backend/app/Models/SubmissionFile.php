<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class SubmissionFile extends Model
{
    use BelongsToSchool;

    protected $table = 'submission_files';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];
}
