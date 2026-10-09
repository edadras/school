<?php

namespace App\Modules\Notifications\Channels;

use App\Models\OutboxNotification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class EmailChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true) || app()->environment('testing');
    }

    public function deliver(OutboxNotification $n): string
    {
        $email = User::whereKey($n->user_id)->value('email');
        if (! $email) {
            return 'skipped';
        }
        Mail::raw($n->title."\n\n".($n->body ?? ''), fn ($m) => $m->to($email)->subject($n->title));

        return 'sent';
    }
}
