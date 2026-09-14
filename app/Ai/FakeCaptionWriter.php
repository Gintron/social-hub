<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Schemas\CaptionSet;
use App\Models\Brand;
use App\Models\ContentItem;

/**
 * Test double. Hands back whatever the test queued, and records what it was asked for.
 */
final class FakeCaptionWriter implements CaptionWriter
{
    /**
     * @var list<array{item: int, violations: list<string>}>
     */
    public array $calls = [];

    /**
     * @var list<CaptionSet|CaptionWriterException>
     */
    private array $queue = [];

    public static function captions(
        string $facebook = 'Objava za Facebook.',
        string $instagram = 'Objava za Instagram.',
        string $hashtags = '#posao #zagreb',
        string $altText = 'Slika oglasa.',
        ?string $note = null,
        string $tiktok = 'Objava za TikTok.',
    ): CaptionSet {
        $set = new CaptionSet;
        $set->facebook = $facebook;
        $set->instagram = $instagram;
        $set->tiktok = $tiktok;
        $set->hashtags = $hashtags;
        $set->alt_text = $altText;
        $set->note = $note;

        return $set;
    }

    public function queue(CaptionSet|CaptionWriterException ...$results): self
    {
        foreach ($results as $result) {
            $this->queue[] = $result;
        }

        return $this;
    }

    public function write(ContentItem $item, Brand $brand, array $violations = []): CaptionResult
    {
        $this->calls[] = ['item' => $item->id, 'violations' => $violations];

        $next = array_shift($this->queue) ?? self::captions();

        if ($next instanceof CaptionWriterException) {
            throw $next;
        }

        return new CaptionResult($next, 'fake-model', 100, 50);
    }
}
