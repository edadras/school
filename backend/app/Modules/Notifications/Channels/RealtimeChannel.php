<?php

namespace App\Modules\Notifications\Channels;

use App\Models\OutboxNotification;
use App\Modules\Realtime\Realtime;

class RealtimeChannel implements NotificationChannel
{
    public function __construct(private Realtime $rt) {}

    public function name(): string
    {
        return 'realtime';
    }

    public function isConfigured(): bool
    {
        return config('broadcasting.default') !== 'null';
    }

    public function deliver(OutboxNotification $n): string
    {
        $this->rt->user($n->user_id, 'notification', ['id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'data' => $n->data]);

        return 'sent';
    }
}
