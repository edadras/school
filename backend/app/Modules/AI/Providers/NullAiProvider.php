<?php

namespace App\Modules\AI\Providers;

use App\Modules\AI\AiException;
use App\Modules\AI\AiProvider;

class NullAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function complete(string $system, array $messages, int $maxTokens): array
    {
        throw new AiException('سرویس هوش مصنوعی پیکربندی نشده است.', 'ai_unconfigured', 503);
    }

    public function supportsAudio(): bool
    {
        return false;
    }

    public function transcribe(string $path, string $mime): string
    {
        throw new AiException('سرویس هوش مصنوعی پیکربندی نشده است.', 'ai_unconfigured', 503);
    }

    public function speak(string $text): array
    {
        throw new AiException('سرویس هوش مصنوعی پیکربندی نشده است.', 'ai_unconfigured', 503);
    }
}
