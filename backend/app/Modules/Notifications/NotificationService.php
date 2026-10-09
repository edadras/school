<?php

namespace App\Modules\Notifications;

use App\Models\OutboxNotification;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * In-app notification outbox. Delivery is idempotent: (user_id, dedupe_key) is unique,
 * so re-running a job or an overlapping scheduler tick can never create a duplicate.
 * Push/email/WebSocket channels read from this table (see docs/realtime.md).
 */
class NotificationService
{
    /** @param  iterable<int>  $userIds */
    public function send(iterable $userIds, string $type, string $dedupeKey, string $title, ?string $body = null, array $data = []): int
    {
        $schoolId = app(CurrentSchool::class)->id();
        $now = now();
        $rows = [];

        foreach (collect($userIds)->unique() as $uid) {
            $rows[] = [
                'school_id' => $schoolId, 'user_id' => $uid, 'type' => $type, 'dedupe_key' => $dedupeKey,
                'title' => $title, 'body' => $body, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += DB::table('notifications_outbox')->insertOrIgnore($chunk);
        }

        // Fan-out happens off the request path; the outbox row is already durable.
        $inserted > 0 && \App\Jobs\DispatchNotifications::dispatch($schoolId)->afterCommit();

        return $inserted;
    }

    public function forUser(int $userId, ?int $sinceId, int $limit = 50)
    {
        return OutboxNotification::query()->where('user_id', $userId)
            ->when($sinceId, fn ($q) => $q->where('id', '>', $sinceId))
            ->orderBy('id')->limit($limit)->get();
    }
}
