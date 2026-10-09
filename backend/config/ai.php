<?php

return [
    // none | anthropic | openai (any OpenAI-compatible endpoint). 'none' => AI features report "unconfigured".
    'provider' => env('AI_PROVIDER', 'none'),
    'timeout' => 45,
    'max_output_tokens' => 1500,
    'anthropic' => [
        'key' => env('AI_API_KEY'), 'model' => env('AI_MODEL', 'claude-sonnet-5-5'), 'base_url' => env('AI_BASE_URL', 'https://api.anthropic.com'),
    ],
    'openai' => [
        'key' => env('AI_API_KEY'), 'model' => env('AI_MODEL', 'gpt-4o-mini'), 'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'stt_model' => env('AI_STT_MODEL', 'whisper-1'), 'tts_model' => env('AI_TTS_MODEL', 'tts-1'), 'tts_voice' => env('AI_TTS_VOICE', 'alloy'),
    ],
    // For cost accounting only (currency units per million tokens). Set to your provider's current price list.
    'price_in_per_mtok' => (float) env('AI_PRICE_IN', 0),
    'price_out_per_mtok' => (float) env('AI_PRICE_OUT', 0),
    'defaults' => ['daily_limit_student' => 30, 'daily_limit_teacher' => 100, 'daily_limit_admin' => 50, 'daily_token_budget' => 300000],
];
