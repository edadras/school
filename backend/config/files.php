<?php

return [
    // Private disk. Production: an S3-compatible bucket with NO public access.
    'disk' => env('FILES_DISK', 'local'),
    'max_mb_default' => 20,
    'signed_url_minutes' => 5,
    // Verified against the real file content (finfo), never the client-supplied type.
    'allowed_mimes' => [
        'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'weba',
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
        'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ],
    // Rendered inline in the browser; everything else is forced to download.
    'inline_mimes' => ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm', 'video/mp4', 'video/webm'],
];
