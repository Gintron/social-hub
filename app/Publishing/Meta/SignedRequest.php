<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use RuntimeException;

/**
 * Meta signs its deauthorize and data-deletion callbacks with `signed_request`:
 * base64url(HMAC-SHA256 of the payload) + "." + base64url(JSON payload).
 *
 * @see https://developers.facebook.com/docs/facebook-login/guides/advanced/deauthorize-callback
 */
final class SignedRequest
{
    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the signature does not verify
     */
    public static function parse(string $signedRequest, ?string $appSecret = null): array
    {
        $appSecret ??= (string) config('meta.app_secret');

        if ($appSecret === '') {
            throw new RuntimeException('META_APP_SECRET is not configured; cannot verify a signed request.');
        }

        $parts = explode('.', $signedRequest, 2);

        if (count($parts) !== 2) {
            throw new RuntimeException('Malformed signed_request.');
        }

        [$encodedSignature, $encodedPayload] = $parts;

        $signature = self::base64UrlDecode($encodedSignature);
        $expected = hash_hmac('sha256', $encodedPayload, $appSecret, true);

        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('signed_request signature does not match the app secret.');
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);

        if (! is_array($payload)) {
            throw new RuntimeException('signed_request payload is not a JSON object.');
        }

        if (mb_strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            throw new RuntimeException('Unexpected signed_request algorithm.');
        }

        return $payload;
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('signed_request is not valid base64url.');
        }

        return $decoded;
    }
}
