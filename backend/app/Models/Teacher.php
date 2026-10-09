<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    use BelongsToSchool;

    protected $table = 'teachers';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}
