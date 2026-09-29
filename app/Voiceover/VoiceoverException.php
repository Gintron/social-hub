<?php

declare(strict_types=1);

namespace App\Voiceover;

use RuntimeException;
use Throwable;

/**
 * Something between the hub and a spoken clip went wrong. A render never fails on it: the video goes
 * out without a voice and the reason is kept where a person can read it.
 *
 * `errorCode` is stable — `not_configured`, `invalid_key`, `missing_permissions`, `quota_exceeded`,
 * `voice_not_found`, `rate_limited`, `unavailable`, `rejected`, `empty_audio`, `script_rejected`,
 * `too_long` — so an alert can be throttled per cause and a panel can say what to do about it.
 */
final class VoiceoverException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'voiceover_error',
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether what to do about it is the panel's business rather than a retry: a key, a plan, a voice.
     */
    public function needsAttention(): bool
    {
        return in_array($this->errorCode, ['invalid_key', 'missing_permissions', 'quota_exceeded', 'voice_not_found', 'not_configured'], true);
    }
}
