<?php

namespace App\Modules\VirtualClassrooms\Media;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Used when no SFU is configured: every operation fails loudly with a stable machine-readable code. */
class NullMediaProvider implements MediaProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function health(): array
    {
        return ['ok' => false, 'detail' => 'MEDIA_PROVIDER is not configured'];
    }

    private function fail(): never
    {
        throw new HttpException(503, 'سرویس کلاس آنلاین پیکربندی نشده است.', null, ['X-Error-Code' => 'media_unconfigured']);
    }

    public function createRoom(string $room, int $maxParticipants): void
    {
        $this->fail();
    }

    public function joinToken(string $room, string $identity, string $displayName, string $role): array
    {
        $this->fail();
    }

    public function setMicrophoneMuted(string $room, string $identity, bool $muted): void
    {
        $this->fail();
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->fail();
    }

    public function endRoom(string $room): void
    {
        // Ending a class must never fail just because the SFU is absent.
    }

    public function parseWebhook(string $body, ?string $authorization): ?array
    {
        return null;
    }
}
