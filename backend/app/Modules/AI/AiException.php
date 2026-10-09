<?php

namespace App\Modules\AI;

class AiException extends \RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'ai_error', public int $status = 502)
    {
        parent::__construct($message);
    }
}
