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

    /** True when an Egress (recording) service and its output storage are configured. */
    public function recordingConfigured(): bool;

    /** Starts a composite recording of the room; returns the provider's recording id. @param string $filepath object key inside the output bucket */
    public function startRecording(string $room, string $filepath): string;

    public function stopRecording(string $recordingId): void;

    /** @return array{event:string, room:?string, identity:?string, egress?:array}|null  null => signature invalid */
    public function parseWebhook(string $body, ?string $authorization): ?array;
}
