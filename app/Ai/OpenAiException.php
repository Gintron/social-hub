<?php

declare(strict_types=1);

namespace App\Ai;

use RuntimeException;
use Throwable;

/**
 * OpenAI did not give an answer the hub can use.
 *
 * `errorCode` is stable — `not_configured`, `invalid_key`, `forbidden`, `quota_exceeded`, `model_not_found`,
 * `rate_limited`, `rejected`, `refused`, `incomplete`, `invalid_output`, `unavailable`, `error` — so a caller
 * can decide what a cause costs (the caption agent falls back to its own text, a voice-over goes without a
 * voice) and an alert can be throttled per cause.
 */
final class OpenAiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'error',
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The model declined to answer, which no retry changes.
     */
    public function refused(): bool
    {
        return $this->errorCode === 'refused';
    }
}
