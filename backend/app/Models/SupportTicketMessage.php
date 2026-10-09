<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    protected $table = 'support_ticket_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['internal' => 'boolean'];
    }
}
