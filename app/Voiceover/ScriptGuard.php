<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Ai\CaptionValidator;
use App\Models\ContentItem;
use App\Rendering\Scene;

/**
 * The last gate before a script is spoken, and — like CaptionValidator, whose rules it applies — not
 * an ornament. The script is written from the items' own fields, so it passes; the gate is for the
 * line a person edited, or a writer added later, that says a price the offer does not carry.
 * A wrong price on a page can be corrected; one said aloud in a published video cannot.
 */
final class ScriptGuard
{
    public function __construct(private readonly CaptionValidator $captions) {}

    /**
     * @param  iterable<ContentItem>  $items  What the video is about; every figure spoken must be theirs.
     * @param  bool  $amounts  Check figures against the items. Off for copy that belongs to no item (a storyboard).
     * @param  list<string>  $trusted  Text the brand wrote itself and the video repeats — a roundup's headline
     *                                 ("Akcije do 50 % popusta"). It is on the cover slide and in the caption as
     *                                 it is; it is not a claim the voice makes about an offer.
     * @return list<string> Empty when the script may be spoken.
     */
    public function check(Script $script, iterable $items, bool $amounts = true, array $trusted = []): array
    {
        $items = is_array($items) ? $items : iterator_to_array($items, false);
        $trusted = array_values(array_filter(array_map('mb_trim', $trusted), fn (string $text): bool => $text !== ''));
        $violations = [];

        foreach ($script->lines as $index => $line) {
            $where = 'Redak '.($index + 1).': ';

            if (preg_match('~https?://|(?<![\p{L}\p{N}])www\.~iu', $line->text) === 1) {
                $violations[] = $where.'glas ne smije čitati poveznicu; adresa ide u tekst objave.';
            }

            // The closing line is the brand's own copy, set in the panel; it says nothing about an offer.
            if ($line->role === Scene::CLOSING || ! $amounts) {
                continue;
            }

            foreach ($this->captions->violationsInText(str_replace($trusted, '', $line->text), $items) as $violation) {
                $violations[] = $where.$violation;
            }
        }

        $limit = (int) config('elevenlabs.max_characters_per_video', 1200);

        if ($script->characters() > $limit) {
            $violations[] = "Tekst voice-overa ima {$script->characters()} znakova, dopušteno je {$limit}.";
        }

        return array_values(array_unique($violations));
    }
}
