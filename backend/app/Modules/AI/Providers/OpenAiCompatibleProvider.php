<?php

namespace App\Modules\AI\Providers;

use App\Modules\AI\AiException;
use App\Modules\AI\AiProvider;
use Illuminate\Support\Facades\Http;

/** Works with OpenAI and any server exposing the same chat/transcription/speech endpoints. */
class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(private array $cfg) {}

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return filled($this->cfg['key'] ?? null);
    }

    private function http()
    {
        return Http::withToken($this->cfg['key'])->timeout(config('ai.timeout'));
    }

    private function url(string $p): string
    {
        return rtrim($this->cfg['base_url'], '/').$p;
    }

    public function complete(string $system, array $messages, int $maxTokens): array
    {
        $r = $this->http()->acceptJson()->post($this->url('/chat/completions'), [
            'model' => $this->cfg['model'], 'max_tokens' => $maxTokens, 'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
        ]);
        if ($r->status() === 429) {
            throw new AiException('سرویس هوش مصنوعی شلوغ است؛ کمی بعد دوباره تلاش کنید.', 'ai_rate_limited', 503);
        }
        if (! $r->successful()) {
            throw new AiException('پاسخ نامعتبر از سرویس هوش مصنوعی (HTTP '.$r->status().').', 'ai_error', 502);
        }

        return ['text' => (string) $r->json('choices.0.message.content', ''), 'input_tokens' => (int) $r->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $r->json('usage.completion_tokens', 0), 'model' => (string) $r->json('model', $this->cfg['model'])];
    }

    public function supportsAudio(): bool
    {
        return true;
    }

    public function transcribe(string $path, string $mime): string
    {
        $r = $this->http()->attach('file', file_get_contents($path), 'audio.'.(explode('/', $mime)[1] ?? 'webm'))
            ->post($this->url('/audio/transcriptions'), ['model' => $this->cfg['stt_model'], 'language' => 'fa']);
        if (! $r->successful()) {
            throw new AiException('تبدیل گفتار به متن ناموفق بود.', 'ai_error', 502);
        }

        return (string) $r->json('text', '');
    }

    public function speak(string $text): array
    {
        $r = $this->http()->post($this->url('/audio/speech'), ['model' => $this->cfg['tts_model'], 'voice' => $this->cfg['tts_voice'], 'input' => $text, 'response_format' => 'mp3']);
        if (! $r->successful()) {
            throw new AiException('تبدیل متن به گفتار ناموفق بود.', 'ai_error', 502);
        }

        return ['bytes' => $r->body(), 'mime' => 'audio/mpeg'];
    }
}
