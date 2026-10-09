<?php

namespace App\Modules\VirtualClassrooms\Media;

/**
 * Boundary to the SFU. Everything else in the app talks to this interface only,
 * so the SFU vendor can be swapped without touching classes, attendance or UI contracts.
 */
interface MediaProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /** @return array{ok:bool, detail:string} */
    public function health(): array;

    public function createRoom(string $room, int $maxParticipants): void;

    /**
     * @param  'host'|'participant'|'observer'  $role
     * @return array{url:string, token:string, ice_servers:list<array>}
     */
    public function joinToken(string $room, string $identity, string $displayName, string $role): array;

    public function setMicrophoneMuted(string $room, string $identity, bool $muted): void;

    public function removeParticipant(string $room, string $identity): void;

    public function endRoom(string $room): void;

    /** @return array{event:string, room:?string, identity:?string}|null  null => signature invalid */
    public function parseWebhook(string $body, ?string $authorization): ?array;
}
