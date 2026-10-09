<?php

namespace Tests\Support;

use App\Modules\AI\AiException;
use App\Modules\AI\AiProvider;

class FakeAiProvider implements AiProvider
{
    public static array $sent = [];

    public static ?string $reply = 'پاسخ آزمایشی';

    public static bool $fail = false;

    public static bool $configured = true;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function complete(string $system, array $messages, int $maxTokens): array
    {
        self::$sent[] = ['system' => $system, 'messages' => $messages];
        if (self::$fail) {
            throw new AiException('upstream down', 'ai_error', 502);
        }

        return ['text' => self::$reply, 'input_tokens' => 100, 'output_tokens' => 50, 'model' => 'fake-1'];
    }

    public function supportsAudio(): bool
    {
        return false;
    }

    public function transcribe(string $path, string $mime): string
    {
        throw new AiException('x', 'ai_audio_unsupported', 501);
    }

    public function speak(string $text): array
    {
        throw new AiException('x', 'ai_audio_unsupported', 501);
    }
}
