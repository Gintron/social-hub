<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\TemplateData;
use Illuminate\Support\Str;

/**
 * Deterministic captions from generic item fields — the fallback when no agent is involved and the
 * baseline the agent is compared against. Mirrors the studentski-poslovi FacebookPostFormatter layout.
 */
final class CaptionBuilder
{
    /**
     * Unicode Mathematical Sans-Serif Bold for A-Z, a-z, 0-9 (social networks have no markup).
     */
    public static function bold(string $text): string
    {
        $out = '';

        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char);
            $out .= match (true) {
                $code >= 65 && $code <= 90 => mb_chr(0x1D5D4 + ($code - 65)),
                $code >= 97 && $code <= 122 => mb_chr(0x1D5EE + ($code - 97)),
                $code >= 48 && $code <= 57 => mb_chr(0x1D7EC + ($code - 48)),
                default => $char,
            };
        }

        return $out;
    }

    public function for(Platform $platform, ContentItem $item, Brand $brand): string
    {
        return match ($platform) {
            Platform::InstagramBusiness => $this->instagram($item, $brand),
            Platform::TikTok => $this->instagram($item, $brand),
            default => $this->facebook($item, $brand),
        };
    }

    /**
     * Caption for a carousel that collects several items: a headline, then a numbered line per
     * slide so the reader can follow along while swiping.
     *
     * @param  \Illuminate\Support\Collection<int, ContentItem>  $items
     */
    public function digest(Platform $platform, \Illuminate\Support\Collection $items, Brand $brand, string $headline): string
    {
        $instagram = $platform === Platform::InstagramBusiness || $platform === Platform::TikTok;
        $sections = [$instagram ? $headline : self::bold($headline)];

        $lines = [];

        foreach ($items->values() as $index => $item) {
            $number = $index + 1;
            $detail = $this->digestDetail($item);

            $lines[] = "{$number}. ".$item->title.($detail !== null ? " — {$detail}" : '');
        }

        $sections[] = implode("\n", $lines);

        if ($instagram) {
            $host = TemplateData::displayUrl($brand->site_url) ?? '';
            $sections[] = '🔗 Link u biu → '.$host;

            $tags = $this->hashtags($items->first(), $brand);
            if ($tags !== []) {
                $sections[] = implode(' ', $tags);
            }
        } elseif (filled($brand->site_url)) {
            $sections[] = self::bold('Sve ponude').":\n".$brand->site_url;
        }

        return self::tidy(implode("\n\n", $sections));
    }

    public function facebook(ContentItem $item, Brand $brand): string
    {
        $sections = [];

        $header = $this->emoji($item).' '.$item->title;
        $sections[] = $header;

        if (filled($item->subtitle)) {
            $sections[] = (string) $item->subtitle;
        }

        $facts = $this->factLines($item);
        if ($facts !== []) {
            $sections[] = implode("\n", $facts);
        }

        if (($item->badges ?? []) !== []) {
            $sections[] = implode("\n", array_map(fn (string $badge): string => '✅ '.$badge, $item->badges));
        }

        if (filled($item->body_text)) {
            $sections[] = (string) $item->body_text;
        }

        $sections[] = self::bold($this->footerLabel($item->kind)).":\n".$item->url;

        return self::tidy(implode("\n\n", $sections));
    }

    public function instagram(ContentItem $item, Brand $brand): string
    {
        $sections = [];
        $sections[] = $this->emoji($item).' '.$item->title;

        if (filled($item->subtitle)) {
            $sections[] = (string) $item->subtitle;
        }

        $facts = $this->factLines($item, bold: false);
        if ($facts !== []) {
            $sections[] = implode("\n", $facts);
        }

        if (($item->badges ?? []) !== []) {
            $sections[] = implode(' · ', array_map(fn (string $badge): string => '✅ '.$badge, $item->badges));
        }

        $excerpt = TemplateData::excerpt($item->body_text, 500);
        if (filled($excerpt)) {
            $sections[] = (string) $excerpt;
        }

        $host = TemplateData::displayUrl($item->url) ?? TemplateData::displayUrl($brand->site_url) ?? '';
        $sections[] = '🔗 Link u biu → '.$host;

        $hashtags = $this->hashtags($item, $brand);
        if ($hashtags !== []) {
            $sections[] = implode(' ', $hashtags);
        }

        $caption = self::tidy(implode("\n\n", $sections));
        $limit = (int) config('hub.limits.ig_caption_chars', 2200);

        return mb_strlen($caption) > $limit ? mb_rtrim(mb_substr($caption, 0, $limit - 1)).'…' : $caption;
    }

    /**
     * @return list<string>
     */
    public function hashtags(ContentItem $item, Brand $brand): array
    {
        $fixed = (array) data_get($brand->voice, 'hashtags', []);
        $fromTags = array_map(fn (string $tag): string => Str::slug($tag, ''), $item->tags ?? []);

        $tags = [];
        foreach ([...$fixed, ...$fromTags, $item->kind->value === 'job' ? 'posao' : null] as $tag) {
            $tag = mb_ltrim((string) $tag, '#');
            if ($tag === '' || ! preg_match('/^[\p{L}\p{N}_]+$/u', $tag)) {
                continue;
            }
            $tags['#'.mb_strtolower($tag)] = true;
        }

        return array_slice(array_keys($tags), 0, (int) config('hub.limits.ig_hashtags', 30));
    }

    private static function tidy(string $text): string
    {
        $text = preg_replace('/[^\S\r\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return mb_trim($text);
    }

    /**
     * The one number that matters per line: the price for a deal, the pay or location for a job.
     */
    private function digestDetail(ContentItem $item): ?string
    {
        $price = $item->price ?? [];

        if (filled($price['current_cents'] ?? null)) {
            $amount = number_format(((int) $price['current_cents']) / 100, 2, ',', '.').' €';
            $discount = $price['discount_pct'] ?? null;

            return filled($discount) ? "{$amount} (−{$discount} %)" : $amount;
        }

        foreach (['PLAĆA', 'SATNICA', 'LOKACIJA'] as $label) {
            $value = $item->fact($label);

            if ($value !== null) {
                return $value;
            }
        }

        return $item->subtitle;
    }

    /**
     * @return list<string>
     */
    private function factLines(ContentItem $item, bool $bold = true): array
    {
        $lines = [];

        foreach ($item->facts ?? [] as $fact) {
            $label = mb_strtoupper((string) $fact['label']).':';
            $lines[] = ($bold ? self::bold($label) : $label).' '.$fact['value'];
        }

        return $lines;
    }

    private function emoji(ContentItem $item): string
    {
        $emoji = data_get($item->raw, 'emoji');

        return is_string($emoji) && $emoji !== '' ? $emoji : TemplateData::defaultEmoji($item->kind);
    }

    private function footerLabel(ContentKind $kind): string
    {
        return match ($kind) {
            ContentKind::Job => 'Detalji o poslu',
            ContentKind::Deal => 'Detalji o akciji',
            ContentKind::Event => 'Detalji o događaju',
            default => 'Više',
        };
    }
}
