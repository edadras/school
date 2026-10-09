<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $guarded = ['id'];

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class);
    }
}
