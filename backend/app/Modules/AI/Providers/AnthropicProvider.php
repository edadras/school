<?php

namespace App\Modules\AI\Providers;

use App\Modules\AI\AiException;
use App\Modules\AI\AiProvider;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements AiProvider
{
    public function __construct(private array $cfg) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return filled($this->cfg['key'] ?? null);
    }

    public function complete(string $system, array $messages, int $maxTokens): array
    {
        $r = Http::withHeaders(['x-api-key' => $this->cfg['key'], 'anthropic-version' => '2023-06-01'])->timeout(config('ai.timeout'))->acceptJson()
            ->post(rtrim($this->cfg['base_url'], '/').'/v1/messages', [
                'model' => $this->cfg['model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $messages,
            ]);
        if ($r->status() === 429) {
            throw new AiException('سرویس هوش مصنوعی شلوغ است؛ کمی بعد دوباره تلاش کنید.', 'ai_rate_limited', 503);
        }
        if (! $r->successful()) {
            throw new AiException('پاسخ نامعتبر از سرویس هوش مصنوعی (HTTP '.$r->status().').', 'ai_error', 502);
        }
        $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");

        return ['text' => $text, 'input_tokens' => (int) $r->json('usage.input_tokens', 0), 'output_tokens' => (int) $r->json('usage.output_tokens', 0), 'model' => (string) $r->json('model', $this->cfg['model'])];
    }

    public function supportsAudio(): bool
    {
        return false;
    }

    public function transcribe(string $path, string $mime): string
    {
        throw new AiException('این ارائه‌دهنده از ورودی صوتی پشتیبانی نمی‌کند.', 'ai_audio_unsupported', 501);
    }

    public function speak(string $text): array
    {
        throw new AiException('این ارائه‌دهنده از خروجی صوتی پشتیبانی نمی‌کند.', 'ai_audio_unsupported', 501);
    }
}
