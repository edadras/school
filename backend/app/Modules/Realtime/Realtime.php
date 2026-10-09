<?php

namespace App\Modules\Realtime;

use App\Models\LessonSession;
use App\Modules\Tenancy\CurrentSchool;

/**
 * Thin facade over Laravel broadcasting. Broadcast failures (Reverb down) must never break
 * the HTTP action that triggered them — state in the DB is the source of truth, clients re-sync with REST.
 */
class Realtime
{
    public function __construct(private CurrentSchool $current) {}

    public function session(LessonSession $s, string $event, array $data = []): void
    {
        $this->emit(new Events\RealtimeEvent("private-school.{$this->current->id()}.session.{$s->id}", $event, $data + ['session_id' => $s->id]));
    }

    public function user(int $userId, string $event, array $data = []): void
    {
        $this->emit(new Events\RealtimeEvent("private-school.{$this->current->id()}.user.$userId", $event, $data));
    }

    public function conversation(int $conversationId, string $event, array $data = []): void
    {
        $this->emit(new Events\RealtimeEvent("private-school.{$this->current->id()}.conversation.$conversationId", $event, $data));
    }

    private function emit(Events\RealtimeEvent $e): void
    {
        try {
            event($e);
        } catch (\Throwable $ex) {
            report($ex);
        }
    }
}
