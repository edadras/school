<?php

namespace App\Modules\Notifications;

use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\OutboxNotification;
use App\Modules\Notifications\Channels\EmailChannel;
use App\Modules\Notifications\Channels\NotificationChannel;
use App\Modules\Notifications\Channels\PushChannel;
use App\Modules\Notifications\Channels\RealtimeChannel;

/**
 * Fan-out of outbox rows to channels, honouring each user's preferences.
 * (notification_id, channel) is unique ⇒ at-most-once per channel even when the job is retried.
 */
class NotificationDispatcher
{
    /** @var list<NotificationChannel> */
    private array $channels;

    public function __construct(RealtimeChannel $rt, PushChannel $push, EmailChannel $mail)
    {
        $this->channels = [$rt, $push, $mail];
    }

    public function dispatchPending(int $limit = 500): int
    {
        $n = 0;
        OutboxNotification::query()->whereNull('dispatched_at')->orderBy('id')->limit($limit)->get()->each(function ($row) use (&$n) {
            $this->dispatchOne($row);
            $n++;
        });

        return $n;
    }

    public function dispatchOne(OutboxNotification $row): void
    {
        foreach ($this->channels as $ch) {
            if (NotificationDelivery::where('notification_id', $row->id)->where('channel', $ch->name())->exists()) {
                continue;
            }
            $status = 'skipped';
            $error = null;
            if (! $this->wanted($row, $ch->name())) {
                $status = 'skipped';
            } elseif (! $ch->isConfigured()) {
                $status = 'unconfigured';
            } else {
                try {
                    $status = $ch->deliver($row);
                } catch (\Throwable $e) {
                    $status = 'failed';
                    $error = mb_substr($e->getMessage(), 0, 255);
                }
            }
            if ($status !== 'failed') {
                NotificationDelivery::firstOrCreate(['notification_id' => $row->id, 'channel' => $ch->name()], ['status' => $status]);
            } else {
                // A failure is recorded once and not auto-retried forever; ops can see it in the table.
                NotificationDelivery::firstOrCreate(['notification_id' => $row->id, 'channel' => $ch->name()], ['status' => 'failed', 'error' => $error]);
            }
        }
        $row->forceFill(['dispatched_at' => now()])->save();
    }

    private function wanted(OutboxNotification $row, string $channel): bool
    {
        $pref = NotificationPreference::where('user_id', $row->user_id)->where('channel', $channel)->whereIn('type', [$row->type, '*'])->get()->sortByDesc(fn ($p) => $p->type === $row->type)->first();
        if ($pref) {
            return $pref->enabled;
        }

        return $channel !== 'email' || in_array($row->type, config('notifications.email_types'), true);
    }
}
