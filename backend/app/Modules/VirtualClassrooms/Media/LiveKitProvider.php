<?php

namespace App\Modules\VirtualClassrooms\Media;

use Illuminate\Support\Facades\Http;

/**
 * LiveKit (open-source SFU) adapter: access tokens are signed locally (HS256),
 * room administration uses the server-side Twirp API, TURN credentials follow the coturn REST scheme.
 */
class LiveKitProvider implements MediaProvider
{
    public function __construct(private array $cfg, private array $turn = []) {}

    public function name(): string
    {
        return 'livekit';
    }

    public function isConfigured(): bool
    {
        return filled($this->cfg['url'] ?? null) && filled($this->cfg['key'] ?? null) && filled($this->cfg['secret'] ?? null);
    }

    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'missing LIVEKIT_* settings'];
        }
        try {
            $r = $this->twirp('ListRooms', [], ['roomList' => true]);

            return ['ok' => $r->successful(), 'detail' => 'HTTP '.$r->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    public function createRoom(string $room, int $maxParticipants): void
    {
        $this->twirp('CreateRoom', ['name' => $room, 'max_participants' => $maxParticipants, 'empty_timeout' => 600], ['roomCreate' => true])->throw();
    }

    public function joinToken(string $room, string $identity, string $displayName, string $role): array
    {
        $host = $role === 'host';
        $observer = $role === 'observer';
        $grant = [
            'room' => $room, 'roomJoin' => true,
            'canSubscribe' => true,
            // Students may publish mic/camera; the host can mute them. Observers (school monitors) are receive-only.
            'canPublish' => ! $observer, 'canPublishData' => ! $observer,
            'roomAdmin' => $host,
        ];
        $token = $this->jwt(['sub' => $identity, 'name' => $displayName, 'video' => $grant], (int) config('media.join_token_ttl'));

        return ['url' => $this->cfg['url'], 'token' => $token, 'ice_servers' => $this->iceServers($identity)];
    }

    public function setMicrophoneMuted(string $room, string $identity, bool $muted): void
    {
        $p = $this->twirp('GetParticipant', ['room' => $room, 'identity' => $identity], ['roomAdmin' => true, 'room' => $room]);
        $p->throw();
        foreach ($p->json('tracks', []) as $track) {
            if (($track['type'] ?? '') === 'AUDIO' || ($track['source'] ?? '') === 'MICROPHONE') {
                $this->twirp('MutePublishedTrack', ['room' => $room, 'identity' => $identity, 'track_sid' => $track['sid'], 'muted' => $muted], ['roomAdmin' => true, 'room' => $room])->throw();
            }
        }
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->twirp('RemoveParticipant', ['room' => $room, 'identity' => $identity], ['roomAdmin' => true, 'room' => $room])->throw();
    }

    public function endRoom(string $room): void
    {
        $r = $this->twirp('DeleteRoom', ['room' => $room], ['roomCreate' => true]);
        if ($r->status() !== 404) {   // a room nobody ever joined does not exist on the SFU: that is already "ended"
            $r->throw();
        }
    }

    /** Egress needs its own service (livekit-egress) and an S3-compatible bucket; without both we say so instead of pretending. */
    public function recordingConfigured(): bool
    {
        $e = $this->cfg['egress'] ?? [];

        return $this->isConfigured() && ! empty($e['bucket']) && ! empty($e['access_key']) && ! empty($e['secret']);
    }

    public function startRecording(string $room, string $filepath): string
    {
        $e = $this->cfg['egress'];
        $s3 = array_filter(['access_key' => $e['access_key'], 'secret' => $e['secret'], 'region' => $e['region'] ?? null, 'endpoint' => $e['endpoint'] ?? null,
            'bucket' => $e['bucket'], 'force_path_style' => (bool) ($e['path_style'] ?? false)], fn ($v) => $v !== null && $v !== '');
        $r = $this->twirp('StartRoomCompositeEgress', [
            'room_name' => $room, 'layout' => 'speaker', 'audio_only' => false,
        ] + (! empty($e['template_url']) ? ['custom_base_url' => $e['template_url']] : []) + [
            'file_outputs' => [['file_type' => 'MP4', 'filepath' => $filepath, 's3' => $s3]],
        ], ['roomRecord' => true], 'livekit.Egress');
        $r->throw();

        return (string) $r->json('egress_id');
    }

    public function stopRecording(string $recordingId): void
    {
        $r = $this->twirp('StopEgress', ['egress_id' => $recordingId], ['roomRecord' => true], 'livekit.Egress');
        if (! in_array($r->status(), [404, 412], true)) {   // already finished / unknown => fine
            $r->throw();
        }
    }

    /** LiveKit signs webhooks with a JWT whose `sha256` claim is the base64 SHA-256 of the body. */
    public function parseWebhook(string $body, ?string $authorization): ?array
    {
        if (! $this->isConfigured() || ! $authorization) {
            return null;
        }
        $claims = $this->verifyJwt(trim(preg_replace('/^Bearer\s+/i', '', $authorization)));
        if (! $claims || ($claims['sha256'] ?? null) !== base64_encode(hash('sha256', $body, true))) {
            return null;
        }
        $e = json_decode($body, true) ?: [];

        $egress = isset($e['egressInfo']) ? [
            'id' => $e['egressInfo']['egressId'] ?? ($e['egressInfo']['egress_id'] ?? null), 'status' => $e['egressInfo']['status'] ?? null,
            'room' => $e['egressInfo']['roomName'] ?? ($e['egressInfo']['room_name'] ?? null),
            'file' => $e['egressInfo']['fileResults'][0] ?? ($e['egressInfo']['file_results'][0] ?? ($e['egressInfo']['file'] ?? null)), 'error' => $e['egressInfo']['error'] ?? null,
        ] : null;

        return ['event' => $e['event'] ?? '', 'room' => $e['room']['name'] ?? ($egress['room'] ?? null), 'identity' => $e['participant']['identity'] ?? null, 'egress' => $egress];
    }

    private function iceServers(string $identity): array
    {
        if (empty($this->turn['urls']) || empty($this->turn['secret'])) {
            return [];
        }
        $user = (time() + (int) ($this->turn['ttl'] ?? 3600)).':'.$identity;

        return [['urls' => array_values($this->turn['urls']), 'username' => $user,
            'credential' => base64_encode(hash_hmac('sha1', $user, $this->turn['secret'], true))]];
    }

    private function twirp(string $method, array $body, array $grant, string $service = 'livekit.RoomService')
    {
        $base = $this->cfg['api_url'] ?: preg_replace('#^ws#', 'http', (string) $this->cfg['url']);

        return Http::withToken($this->jwt(['video' => $grant], 60))->acceptJson()->timeout(10)
            ->post(rtrim($base, '/')."/twirp/$service/$method", (object) $body);
    }

    private function jwt(array $claims, int $ttl): string
    {
        $now = time();
        $payload = ['iss' => $this->cfg['key'], 'nbf' => $now - 5, 'exp' => $now + $ttl] + $claims;
        $enc = fn ($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
        $head = $enc(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = $enc($payload);
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$head.$body", $this->cfg['secret'], true)), '+/', '-_'), '=');

        return "$head.$body.$sig";
    }

    private function verifyJwt(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$parts[0].$parts[1]", $this->cfg['secret'], true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $parts[2])) {
            return null;
        }
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($claims) && ($claims['iss'] ?? null) === $this->cfg['key'] && ($claims['exp'] ?? 0) > time() ? $claims : null;
    }
}
