<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolSubscription extends Model
{
    protected $table = 'school_subscriptions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'documents' => 'array', 'decided_at' => 'datetime'];
    }
}
