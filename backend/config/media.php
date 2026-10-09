<?php

return [
    // none | livekit. 'none' => live classes report "unconfigured"; nothing is faked.
    'provider' => env('MEDIA_PROVIDER', 'none'),
    'max_participants' => (int) env('MEDIA_MAX_PARTICIPANTS', 60),   // per room; tune after load tests
    'join_token_ttl' => 3600,
    'livekit' => [
        'url' => env('LIVEKIT_URL'),                // wss://… (clients)
        'api_url' => env('LIVEKIT_API_URL'),        // https://… (server-to-server); defaults to url with http(s)
        'key' => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),
    ],
    // Optional external TURN (coturn with use-auth-secret). LiveKit also ships a built-in TURN.
    'turn' => [
        'urls' => array_filter(explode(',', (string) env('TURN_URL', ''))),
        'secret' => env('TURN_SECRET'),
        'ttl' => 3600,
    ],
];
