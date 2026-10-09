<?php

namespace App\Modules\Notifications\Channels;

use App\Models\OutboxNotification;

interface NotificationChannel
{
    public function name(): string;

    public function isConfigured(): bool;

    /** @return string sent|skipped  — throw on failure */
    public function deliver(OutboxNotification $n): string;
}
