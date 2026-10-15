<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Publishing\FailureAdvice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailureAdviceTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function knownCodes(): array
    {
        return array_combine(
            $codes = ['content_expired', 'dead_link', 'link_check_failed', 'stale_content', 'restricted_goods', 'media_not_ready', 'token_invalid', 'permission_denied', 'invalid_parameter', 'rate_limited', 'title_invalid', 'description_invalid'],
            array_map(fn (string $code): array => [$code], $codes),
        );
    }

    #[DataProvider('knownCodes')]
    public function test_a_known_cause_has_a_sentence_that_says_what_to_do(string $code): void
    {
        $advice = FailureAdvice::for($code);

        $this->assertNotNull($advice);
        $this->assertStringEndsWith('.', $advice);
        // It is for a person: no internal names in it.
        $this->assertStringNotContainsString('_', $advice);
        $this->assertStringNotContainsString($code, $advice);
    }

    public function test_every_platform_names_its_unreachable_the_same_way(): void
    {
        $expected = FailureAdvice::for('graph_unreachable');

        $this->assertNotNull($expected);
        foreach (['tiktok_unreachable', 'youtube_unreachable', 'upload_unreachable', 'graph_transient', 'tiktok_transient', 'youtube_transient', 'transient'] as $code) {
            $this->assertSame($expected, FailureAdvice::for($code), $code);
        }
    }

    public function test_a_cause_nobody_knows_gets_no_advice_rather_than_a_guess(): void
    {
        foreach ([null, '', 'permanent', 'publish_failed', 'unexpected', 'ig_no_media_id', 'something_new'] as $code) {
            $this->assertNull(FailureAdvice::for($code), (string) $code);
        }
    }
}
