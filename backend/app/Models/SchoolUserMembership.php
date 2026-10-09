<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolUserMembership extends Model
{
    protected $table = 'school_user_memberships';

    protected $guarded = ['id'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
