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
        // Recording (LiveKit Egress service + S3-compatible output). All three must be set, otherwise recording reports "unconfigured".
        'egress' => [
            'bucket' => env('EGRESS_S3_BUCKET'), 'access_key' => env('EGRESS_S3_ACCESS_KEY'), 'secret' => env('EGRESS_S3_SECRET'),
            'region' => env('EGRESS_S3_REGION'), 'endpoint' => env('EGRESS_S3_ENDPOINT'), 'path_style' => (bool) env('EGRESS_S3_PATH_STYLE', false),
            // Self-hosted recording template (infra/egress-template). Empty => egress uses LiveKit's hosted template (needs internet).
            'template_url' => env('EGRESS_TEMPLATE_URL'),
            'disk' => env('EGRESS_FILES_DISK', 's3'),       // Laravel disk pointing at the same bucket (used to serve playback links)
        ],
    ],
    // Optional external TURN (coturn with use-auth-secret). LiveKit also ships a built-in TURN.
    'turn' => [
        'urls' => array_filter(explode(',', (string) env('TURN_URL', ''))),
        'secret' => env('TURN_SECRET'),
        'ttl' => 3600,
    ],
];
