<?php

namespace Tests\Support;

use App\Modules\VirtualClassrooms\Media\MediaProvider;

class FakeMediaProvider implements MediaProvider
{
    public static array $calls = [];

    public static bool $configured = true;

    public static bool $failEndRoom = false;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function health(): array
    {
        return ['ok' => true, 'detail' => 'fake'];
    }

    public function createRoom(string $room, int $max): void
    {
        self::$calls[] = ['createRoom', $room, $max];
    }

    public function joinToken(string $room, string $identity, string $displayName, string $role): array
    {
        self::$calls[] = ['join', $room, $identity, $role];

        return ['url' => 'wss://sfu.test', 'token' => "tok-$identity-$role", 'ice_servers' => []];
    }

    public function setMicrophoneMuted(string $room, string $identity, bool $muted): void
    {
        self::$calls[] = ['mute', $room, $identity, $muted];
    }

    public function removeParticipant(string $room, string $identity): void
    {
        self::$calls[] = ['remove', $room, $identity];
    }

    public function endRoom(string $room): void
    {
        if (self::$failEndRoom) {
            throw new \RuntimeException('sfu down');
        }
        self::$calls[] = ['endRoom', $room];
    }

    public function parseWebhook(string $body, ?string $authorization): ?array
    {
        return $authorization === 'valid' ? json_decode($body, true) : null;
    }
}
