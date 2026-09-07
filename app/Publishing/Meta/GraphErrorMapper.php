<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\Exceptions\TransientPublishException;
use Illuminate\Http\Client\Response;

/**
 * Translates Graph API error envelopes into the hub's retry semantics.
 *
 * @see https://developers.facebook.com/docs/graph-api/guide/error-handling
 */
final class GraphErrorMapper
{
    private const TOKEN_CODES = [190, 102];

    private const TOKEN_SUBCODES = [458, 459, 460, 463, 464, 467];

    private const RATE_CODES = [4, 17, 32, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80007, 80008];

    private const TRANSIENT_CODES = [1, 2, 341];

    public function map(Response $response): PublishException
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? []) : [];

        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $type = (string) ($error['type'] ?? '');
        $message = (string) ($error['message'] ?? mb_substr($response->body(), 0, 300));
        $trace = (string) ($error['fbtrace_id'] ?? '');

        $summary = sprintf('Graph API %s (code %d%s%s): %s', $response->status(), $code, $subcode ? "/{$subcode}" : '', $trace ? ", trace {$trace}" : '', $message);

        if (in_array($code, self::TOKEN_CODES, true) || in_array($subcode, self::TOKEN_SUBCODES, true) || $type === 'OAuthException' && $code === 190) {
            return new TokenInvalidException($summary);
        }

        if (in_array($code, self::RATE_CODES, true) || $response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 900);

            return new RateLimitedException($summary, max(60, $retryAfter));
        }

        if ($response->serverError() || in_array($code, self::TRANSIENT_CODES, true)) {
            return new TransientPublishException($summary, 'graph_transient');
        }

        return new PermanentPublishException($summary, match (true) {
            $code === 10 || ($code >= 200 && $code <= 299) => 'permission_denied',
            $code === 100 => 'invalid_parameter',
            $code === 368 => 'blocked',
            default => 'graph_error',
        });
    }
}
