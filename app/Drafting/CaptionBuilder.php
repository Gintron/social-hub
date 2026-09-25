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
 * Deterministic captions from generic item fields — what auto-publish posts, the fallback when the
 * agent's text fails validation, and the baseline the agent is compared against.
 *
 * One shape per network: a Facebook group gets the full listing (the studentski-poslovi
 * FacebookPostFormatter layout, contacts included); a Page, Instagram and TikTok open with the hook.
 */
final class CaptionBuilder
{
    /**
     * Hashtags per single post where a network rewards a few relevant ones over a wall of them.
     */
    private const FEW_HASHTAGS = 5;

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
            Platform::TikTok => $this->tiktok($item, $brand),
            Platform::FacebookPage => $this->facebookPage($item, $brand),
            default => $this->facebook($item, $brand),
        };
    }

    /**
     * The line a scrolling reader decides on: the figure and the first fact beside it
     * ("💰 7.50 €/H · Lovran"). The hook slide leads with the same (TemplateData::forItem()).
     */
    public function hook(ContentItem $item): string
    {
        $parts = [];
        $figure = Highlights::figure($item);

        if ($figure !== null) {
            $parts[] = $this->isComparison($item) ? '🥇 '.$this->figureText($item) : '💰 '.$figure['value'];
        }

        foreach (Highlights::points($item, 1) as $point) {
            $parts[] = $point;
        }

        return implode(' · ', $parts);
    }

    /**
     * A Page post: the hook up front, the facts, a short pitch and where the link is — no contact
     * lines, those belong on the listing and in the group post. The link itself goes in the first
     * comment (FacebookPagePublisher): Facebook shows a post with an outside link to fewer people.
     */
    public function facebookPage(ContentItem $item, Brand $brand): string
    {
        $sections = [$this->emoji($item).' '.$item->title];

        if (($hook = $this->hook($item)) !== '') {
            $sections[] = $hook;
        }

        if (filled($item->subtitle)) {
            $sections[] = (string) $item->subtitle;
        }

        // A comparison's body already lists every row with its product; the facts would repeat it.
        $facts = $this->isComparison($item) ? [] : $this->factLines($item);
        if ($facts !== []) {
            $sections[] = implode("\n", $facts);
        }

        if (($item->badges ?? []) !== []) {
            $sections[] = implode("\n", array_map(fn (string $badge): string => '✅ '.$badge, $item->badges));
        }

        $excerpt = $this->body($item, 400);
        if (filled($excerpt)) {
            $sections[] = (string) $excerpt;
        }

        if (filled($item->url)) {
            $sections[] = '👇 '.self::bold($item->cta['label'] ?? $this->footerLabel($item->kind)).' u prvom komentaru';
        }

        $fixed = array_slice($this->hashtags($item, $brand, fixedOnly: true), 0, 3);
        if ($fixed !== []) {
            $sections[] = implode(' ', $fixed);
        }

        return self::tidy(implode("\n\n", $sections));
    }

    /**
     * TikTok reads the first line as a photo post's title (90 UTF-16 units), so it carries the
     * whole pitch; the rest is a short description and a few hashtags.
     */
    public function tiktok(ContentItem $item, Brand $brand): string
    {
        $hook = Highlights::figure($item) !== null ? $this->figureText($item) : (Highlights::points($item, 1)[0] ?? null);
        $sections = [$this->emoji($item).' '.$item->title.($hook !== null ? ' · '.$hook : '')];

        $details = array_filter([
            filled($item->subtitle) ? (string) $item->subtitle : null,
            ...Highlights::points($item, 2),
        ]);
        if ($details !== []) {
            $sections[] = implode(' · ', $details);
        }

        if (($item->badges ?? []) !== []) {
            $sections[] = implode(' · ', array_map(fn (string $badge): string => '✅ '.$badge, $item->badges));
        }

        if (($pitch = $this->pitch($brand)) !== null) {
            $sections[] = $pitch;
        }

        $host = TemplateData::displayUrl($item->url) ?? TemplateData::displayUrl($brand->site_url) ?? '';
        $sections[] = '🔗 Link u biu → '.$host;

        $hashtags = array_slice($this->hashtags($item, $brand), 0, self::FEW_HASHTAGS);
        if ($hashtags !== []) {
            $sections[] = implode(' ', $hashtags);
        }

        return self::tidy(implode("\n\n", $sections));
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

            // The caption supports the slides; it must not become a catalogue dump. Long source
            // titles stay available on the linked page while the social caption remains scannable.
            $title = Str::limit((string) $item->title, 72);
            $lines[] = "{$number}. {$title}".($detail !== null ? " — {$detail}" : '');
        }

        $sections[] = implode("\n", $lines);

        if ($instagram) {
            if (($pitch = $this->pitch($brand)) !== null) {
                $sections[] = $pitch;
            }

            $host = TemplateData::displayUrl($brand->site_url) ?? '';
            $sections[] = "💾 Spremi popis i pošalji ga osobi s kojom kupuješ\n🔗 Link u biu → {$host}";

            $tags = $this->hashtags($items->first(), $brand);
            if ($tags !== []) {
                $sections[] = implode(' ', $tags);
            }
        } elseif (filled($brand->site_url)) {
            // A Page gets the link as its first comment; a group post is pasted whole by a human.
            $sections[] = $platform === Platform::FacebookPage
                ? '👇 '.self::bold('Sve ponude').' u prvom komentaru'
                : self::bold('Sve ponude').":\n".$brand->site_url;
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

        $facts = $this->isComparison($item) ? [] : $this->factLines($item);
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

    /**
     * Instagram folds a caption after about 125 characters, so the title and the hook come first;
     * then the details, where to apply (links are not clickable there) and a nudge to share.
     */
    public function instagram(ContentItem $item, Brand $brand): string
    {
        $opening = $this->emoji($item).' '.$item->title;

        if (($hook = $this->hook($item)) !== '') {
            $opening .= "\n".$hook;
        }

        // Whose offer it is goes above the fold, next to the figure — a price with no shop beside it
        // reads as the brand's own. Three paragraphs down it is behind "… više".
        if (filled($item->subtitle)) {
            $opening .= "\n".$item->subtitle;
        }

        $sections = [$opening];

        if (($item->badges ?? []) !== []) {
            $sections[] = implode(' · ', array_map(fn (string $badge): string => '✅ '.$badge, $item->badges));
        }

        $excerpt = $this->body($item, 400);
        if (filled($excerpt)) {
            $sections[] = (string) $excerpt;
        }

        if (($pitch = $this->pitch($brand)) !== null) {
            $sections[] = $pitch;
        }

        $host = TemplateData::displayUrl($item->url) ?? TemplateData::displayUrl($brand->site_url) ?? '';
        $sections[] = '🔗 Link u biu → '.$host."\n📤 Pošalji prijatelju kojem ovo treba";

        $hashtags = array_slice($this->hashtags($item, $brand), 0, self::FEW_HASHTAGS);
        if ($hashtags !== []) {
            $sections[] = implode(' ', $hashtags);
        }

        $caption = self::tidy(implode("\n\n", $sections));
        $limit = (int) config('hub.limits.ig_caption_chars', 2200);

        return mb_strlen($caption) > $limit ? mb_rtrim(mb_substr($caption, 0, $limit - 1)).'…' : $caption;
    }

    /**
     * The brand's one sentence about itself (voice.pitch) for networks where the link is not
     * clickable. There the post shows someone else's offer and a "link in bio", and without a
     * reason to follow it nobody does: a paid TikTok deal post of that shape reached ~6 000 viewers
     * in September 2026 and brought not one registration.
     */
    public function pitch(Brand $brand): ?string
    {
        $pitch = mb_trim((string) data_get($brand->voice, 'pitch', ''));

        return $pitch === '' ? null : '📲 '.$pitch;
    }

    /**
     * @return list<string>
     */
    public function hashtags(ContentItem $item, Brand $brand, bool $fixedOnly = false): array
    {
        $fixed = (array) data_get($brand->voice, 'hashtags', []);
        $fromTags = $fixedOnly ? [] : array_map(fn (string $tag): string => Str::slug($tag, ''), $item->tags ?? []);
        $kindTag = ! $fixedOnly && $item->kind->value === 'job' ? 'posao' : null;

        $tags = [];
        foreach ([...$fixed, ...$fromTags, $kindTag] as $tag) {
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

    private function isComparison(ContentItem $item): bool
    {
        return $item->kind === ContentKind::Comparison;
    }

    /**
     * The figure as a caption says it: a comparison's names the shop ("Lidl 9,98 €/kg"), because
     * a price per kilogram with no shop beside it answers nothing.
     */
    private function figureText(ContentItem $item): string
    {
        $figure = Highlights::figure($item) ?? [];
        $value = (string) ($figure['value'] ?? '');

        return $this->isComparison($item) && filled($figure['label'] ?? null) ? $figure['label'].' '.$value : $value;
    }

    /**
     * The body as a caption carries it. A comparison's is one line per shop and stays that way;
     * squeezed into one paragraph like a listing's excerpt, the rows run into each other.
     */
    private function body(ContentItem $item, int $max): ?string
    {
        if ($this->isComparison($item) && filled($item->body_text)) {
            $body = mb_trim((string) $item->body_text);

            return mb_strlen($body) <= $max ? $body : mb_rtrim(mb_substr($body, 0, $max - 1)).'…';
        }

        return TemplateData::excerpt($item->body_text, $max);
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
            ContentKind::Comparison => 'Usporedi sve ponude',
            default => 'Više',
        };
    }
}
