<?php

namespace App\Modules\Notifications\Channels;

use App\Models\DeviceToken;
use App\Models\OutboxNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** FCM HTTP v1 (covers Android, iOS via APNs bridge and Web Push through the Firebase JS SDK). */
class PushChannel implements NotificationChannel
{
    public function name(): string
    {
        return 'push';
    }

    public function isConfigured(): bool
    {
        $c = config('notifications.fcm');

        return filled($c['project_id']) && filled($c['service_account_json']) && is_readable($c['service_account_json']);
    }

    public function deliver(OutboxNotification $n): string
    {
        $tokens = DeviceToken::where('user_id', $n->user_id)->get();
        if ($tokens->isEmpty()) {
            return 'skipped';
        }
        $access = $this->accessToken();
        $project = config('notifications.fcm.project_id');
        foreach ($tokens as $t) {
            $r = Http::withToken($access)->timeout(10)->post("https://fcm.googleapis.com/v1/projects/$project/messages:send", ['message' => [
                'token' => $t->token,
                'notification' => ['title' => $n->title, 'body' => (string) $n->body],
                'data' => ['type' => $n->type, 'id' => (string) $n->id] + array_map('strval', array_filter((array) $n->data, 'is_scalar')),
                'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => str_starts_with($n->type, 'bell.') ? 'bell' : 'default', 'sound' => 'default']],
            ]]);
            if (in_array($r->json('error.status'), ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                $t->delete();          // stale token
            } elseif (! $r->successful()) {
                throw new \RuntimeException('FCM HTTP '.$r->status());
            }
        }

        return 'sent';
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', 3000, function () {
            $sa = json_decode(file_get_contents(config('notifications.fcm.service_account_json')), true);
            $now = time();
            $b64 = fn ($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
            $unsigned = $b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$b64(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]);
            openssl_sign($unsigned, $sig, $sa['private_key'], 'sha256WithRSAEncryption');
            $r = Http::asForm()->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=')]);

            return $r->throw()->json('access_token');
        });
    }
}
