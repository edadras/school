<?php

namespace App\Models;

use App\Modules\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use BelongsToSchool, SoftDeletes;

    protected $table = 'messages';

    // school_id is never mass-assignable; it is injected from the server-side tenant context.
    protected $guarded = ['id', 'school_id'];

    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
