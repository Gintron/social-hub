<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Drafting\Highlights;
use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\Scene;
use App\Rendering\TemplateData;
use Illuminate\Support\Collection;

/**
 * Writes what a video says, from the same fields the slides are drawn from.
 *
 * Deterministic on purpose, like CaptionBuilder: a voice-over is a claim in a published video, so
 * every figure comes from the item's own price and facts (through Highlights, the code that also
 * decides what the hook slide shouts) and the wording around it is fixed. Nothing is left to a model's
 * memory of what a loaf of bread costs. The result is written text — digits and symbols — that
 * SpokenCroatian turns into speech.
 *
 * A slide is spoken over by what it shows, no more: the hook says the product and the figure, the card
 * adds what the hook left out (the discount, the validity, the employer), the end card says what the
 * brand asks of the viewer. A slide with nothing to add is left to the music. The struck-through old
 * price is not read: it costs three seconds of a video that is worth watching for its first three.
 */
final class ScriptBuilder
{
    /** A line longer than this loses its later sentences: a slide is on screen for seconds, and about 130 characters is nine of them. */
    private const MAX_LINE_CHARACTERS = 130;

    private const MAX_TITLE_CHARACTERS = 60;

    /**
     * @param  list<Scene>  $scenes  The slides of the video (ScenePlanner).
     * @param  Collection<int, ContentItem>  $items  Every item of the draft.
     * @param  string|null  $headline  The roundup's headline (the draft's title).
     */
    public function build(Brand $brand, array $scenes, Collection $items, ?string $headline = null): Script
    {
        $withHook = array_any($scenes, fn (Scene $scene): bool => $scene->role === Scene::HOOK);
        $sharedShop = $this->sharedShop($items);

        return new Script(array_map(
            fn (Scene $scene): ScriptLine => new ScriptLine($scene->role, $this->limit(match ($scene->role) {
                Scene::COVER => $this->cover($items, $headline),
                Scene::CLOSING => $this->closing($brand),
                Scene::HOOK => $scene->item === null ? '' : $this->headline($scene->item, showShop: true),
                default => $scene->item === null
                    ? ''
                    // After a hook a card adds to it; with none (a roundup, one plain slide) it has to say it all.
                    : ($withHook ? $this->details($scene->item) : $this->headline($scene->item, showShop: ! $sharedShop, discount: true)),
            })),
            $scenes,
        ));
    }

    /**
     * A script somebody wrote or edited, one line per slide in the order of the video. Lines beyond the
     * slides are dropped and slides beyond the lines are left to the music.
     *
     * @param  list<Scene>  $scenes
     * @param  list<string>  $lines
     */
    public function fromLines(array $scenes, array $lines): Script
    {
        $lines = array_values($lines);

        return new Script(array_map(
            fn (Scene $scene, int $index): ScriptLine => new ScriptLine($scene->role, mb_trim((string) ($lines[$index] ?? ''))),
            $scenes,
            array_keys($scenes),
        ));
    }

    /**
     * The brand's closing line: what it wrote for the voice in the panel, else its own call to action
     * and first step, else its one sentence about itself.
     */
    public function closing(Brand $brand): string
    {
        $outro = $brand->voiceoverSettings()->outro;

        if ($outro !== null) {
            return $outro;
        }

        $sentences = array_filter([
            $this->sentence((string) data_get($brand->voice, 'cta', '')),
            $this->sentence((string) data_get($brand->voice, 'activation', '')),
        ]);

        return $sentences !== []
            ? implode(' ', $sentences)
            : $this->sentence((string) data_get($brand->voice, 'pitch', ''));
    }

    /**
     * @param  Collection<int, ContentItem>  $items
     */
    private function cover(Collection $items, ?string $headline): string
    {
        $parts = [$this->sentence((string) $headline)];

        // The cover slide's own badge: the deepest cut in the roundup, from the same price field.
        $deepest = (int) $items->map(fn (ContentItem $item): int => (int) (($item->price ?? [])['discount_pct'] ?? 0))->max();

        if ($deepest > 0) {
            $parts[] = "Popusti do {$deepest} %.";
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * The line a viewer decides on: what it is and the figure beside it.
     */
    private function headline(ContentItem $item, bool $showShop, bool $discount = false): string
    {
        return match ($item->kind) {
            ContentKind::Deal => $this->dealHeadline($item, $showShop, $discount),
            ContentKind::Job => $this->jobHeadline($item),
            ContentKind::Comparison => $this->comparisonHeadline($item),
            default => $this->genericHeadline($item),
        };
    }

    /**
     * What a card adds to the hook the viewer has just seen.
     */
    private function details(ContentItem $item): string
    {
        return match ($item->kind) {
            ContentKind::Deal => $this->dealDetails($item),
            ContentKind::Job => $this->jobDetails($item),
            ContentKind::Comparison => $this->comparisonDetails($item),
            default => $this->genericDetails($item),
        };
    }

    private function dealHeadline(ContentItem $item, bool $showShop, bool $discount): string
    {
        $title = $this->title($item->title);
        // The chain's name is left as it stands: after "u trgovini" a name needs no case of its own.
        $shop = $showShop && filled($item->subtitle) ? ' u trgovini '.mb_trim((string) $item->subtitle) : '';
        $figure = Highlights::figure($item)['value'] ?? null;

        $line = match (true) {
            $figure === null => "{$title}{$shop}.",
            // "za 1,49 €" reads as a price; a figure the site worded itself ("od 1,49 €/kg") stands alone.
            preg_match('/^\d/u', $figure) === 1 => "{$title}{$shop} za {$figure}.",
            default => "{$title}{$shop}. {$this->sentence($figure)}",
        };

        return $discount && ($percent = $this->discount($item)) !== null ? "{$line} Popust {$percent} %." : $line;
    }

    private function dealDetails(ContentItem $item): string
    {
        $parts = [];

        if (($percent = $this->discount($item)) !== null) {
            $parts[] = "Popust {$percent} %.";
        }

        if (($validity = $this->validity($item)) !== null) {
            $parts[] = $validity;
        }

        return implode(' ', $parts);
    }

    private function jobHeadline(ContentItem $item): string
    {
        // Exactly what the job slide shows: the title, the pay under its own label, the place.
        $job = TemplateData::jobSummary($item);
        $parts = [$this->sentence($this->title($job['title']))];

        if ($job['pay'] !== null) {
            $parts[] = mb_ucfirst(mb_strtolower($job['pay_label'])).': '.$this->sentence((string) $job['pay']);
        }

        if (filled($job['location'])) {
            $parts[] = 'Lokacija: '.$this->sentence((string) $job['location']);
        }

        return implode(' ', $parts);
    }

    private function jobDetails(ContentItem $item): string
    {
        $parts = [];

        if (filled($item->subtitle)) {
            $parts[] = 'Poslodavac: '.$this->sentence((string) $item->subtitle);
        }

        $badges = array_values(array_filter(array_map(
            fn (mixed $badge): string => mb_trim((string) $badge),
            array_slice($item->badges ?? [], 0, 4),
        )));

        if ($badges !== []) {
            $parts[] = $this->sentence(mb_ucfirst($this->enumerate($badges)));
        }

        return implode(' ', $parts);
    }

    private function comparisonHeadline(ContentItem $item): string
    {
        $figure = Highlights::figure($item);
        $line = $this->sentence($this->title($item->title));

        if ($figure === null) {
            return $line;
        }

        $lead = preg_match('/(?<![\p{L}\p{N}])najjeftinije(?![\p{L}\p{N}])/iu', $line) === 1
            ? 'U trgovini'
            : 'Najjeftinije:';

        // The cheapest row and whose it is: a price per kilogram with no shop beside it answers nothing.
        return "{$line} {$lead} {$figure['label']}, {$figure['value']}.";
    }

    private function comparisonDetails(ContentItem $item): string
    {
        $points = Highlights::points($item, 2);

        return $points === [] ? '' : 'Zatim '.implode(', ', $points).'.';
    }

    private function genericHeadline(ContentItem $item): string
    {
        $parts = [$this->sentence($this->title($item->title))];

        foreach (Highlights::points($item, 2) as $point) {
            $parts[] = $this->sentence($point);
        }

        return implode(' ', $parts);
    }

    private function genericDetails(ContentItem $item): string
    {
        $excerpt = TemplateData::excerpt($item->body_text, 140);

        return $excerpt === null ? '' : $this->sentence($excerpt);
    }

    /**
     * "Vrijedi do 30.09.2026." — end-of-day expiries arrive as 23:59:59Z, and the slide prints them in
     * UTC too, so the voice and the slide name the same day.
     */
    private function validity(ContentItem $item): ?string
    {
        return $item->expires_at === null ? null : 'Vrijedi do '.$item->expires_at->utc()->format('d.m.Y').'.';
    }

    private function discount(ContentItem $item): ?int
    {
        $percent = (int) (($item->price ?? [])['discount_pct'] ?? 0);

        return $percent > 0 ? $percent : null;
    }

    /**
     * Whether every item of a roundup is one shop's: then the cover says which, and the cards need not
     * repeat it.
     *
     * @param  Collection<int, ContentItem>  $items
     */
    private function sharedShop(Collection $items): bool
    {
        return $items->count() > 1
            && $items->map(fn (ContentItem $item): string => mb_trim((string) $item->subtitle))->unique()->count() === 1
            && filled($items->first()?->subtitle);
    }

    /**
     * A source title shortened at a word, for a slide's few seconds; the slide clamps it too.
     */
    private function title(string $title): string
    {
        $title = mb_trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        if (mb_strlen($title) <= self::MAX_TITLE_CHARACTERS) {
            return $title;
        }

        $cut = mb_substr($title, 0, self::MAX_TITLE_CHARACTERS);

        // Cut at a word — unless the limit falls exactly at the end of one.
        if (mb_substr($title, self::MAX_TITLE_CHARACTERS, 1) !== ' ') {
            $cut = mb_substr($cut, 0, (int) mb_strrpos($cut, ' ') ?: self::MAX_TITLE_CHARACTERS);
        }

        $cut = mb_rtrim($cut, ' ,;:-–—');

        // A cut that ends on "u" or "s" leaves the sentence hanging on a word with nothing after it.
        while (preg_match('/\s(?:s|sa|u|i|a|o|na|za|od|do|uz|iz|po|te|ili|bez|kao)$/iu', $cut) === 1) {
            $cut = mb_rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ' ,;:-–—');
        }

        return $cut;
    }

    /**
     * "a, b i c".
     *
     * @param  list<string>  $words
     */
    private function enumerate(array $words): string
    {
        $words = array_map(fn (string $word, int $index): string => $index === 0 ? $word : mb_lcfirst($word), $words, array_keys($words));

        if (count($words) < 2) {
            return $words[0] ?? '';
        }

        $last = array_pop($words);

        return implode(', ', $words).' i '.$last;
    }

    private function sentence(string $text): string
    {
        $text = mb_trim($text);

        if ($text === '') {
            return '';
        }

        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }

    /**
     * Whole sentences up to the limit, and at least the first.
     */
    private function limit(string $line): string
    {
        $line = mb_trim(preg_replace('/\s+/u', ' ', $line) ?? $line);

        if (mb_strlen($line) <= self::MAX_LINE_CHARACTERS) {
            return $line;
        }

        $kept = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
            if ($kept !== '' && mb_strlen($kept.' '.$sentence) > self::MAX_LINE_CHARACTERS) {
                break;
            }

            $kept = mb_trim($kept.' '.$sentence);
        }

        return $kept;
    }
}
