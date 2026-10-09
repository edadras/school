<?php

namespace App\Modules\AI;

/** Replaceable model backend. The rest of the app never touches vendor APIs directly. */
interface AiProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @param  list<array{role:'user'|'assistant', content:string}>  $messages
     * @return array{text:string, input_tokens:int, output_tokens:int, model:string}
     *
     * @throws AiException
     */
    public function complete(string $system, array $messages, int $maxTokens): array;

    public function supportsAudio(): bool;

    /** @throws AiException */
    public function transcribe(string $path, string $mime): string;

    /** @return array{bytes:string, mime:string}
     * @throws AiException */
    public function speak(string $text): array;
}
