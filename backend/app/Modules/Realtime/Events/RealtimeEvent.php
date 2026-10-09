<?php

namespace App\Modules\Realtime\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/** Generic private-channel event. Queued (broadcast queue) so request latency is unaffected. */
class RealtimeEvent implements ShouldBroadcast
{
    use SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public string $channel, public string $name, public array $data) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(preg_replace('/^private-/', '', $this->channel))];
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
