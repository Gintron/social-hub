<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Rendering\Scene;
use Illuminate\Support\Collection;

/**
 * A video's voice from start to finish: write what it says (or take what a person wrote), check it,
 * spell it out, have every line spoken — from the cache where it has been said before — and hand the
 * renderer the clips.
 *
 * It throws VoiceoverException when it cannot. The caller decides what that costs; for a render it
 * costs the voice, never the video.
 */
final class Narrator
{
    public function __construct(
        private readonly ScriptBuilder $builder,
        private readonly ScriptGuard $guard,
        private readonly SpokenCroatian $spoken,
        private readonly Synthesizer $synthesizer,
    ) {}

    /**
     * What the video would say, as written — for showing to a person, and for a re-render that keeps
     * the words. Nothing is spoken or paid for.
     *
     * @param  list<Scene>  $scenes
     */
    public function script(PostDraft $draft, array $scenes): Script
    {
        return $this->builder->build($draft->brand, $scenes, $draft->contentItems, $draft->title);
    }

    /**
     * @param  list<Scene>  $scenes  The slides of the video (ScenePlanner).
     * @param  list<string>|null  $lines  A script a person wrote, one line per slide; null to write it from the items.
     * @return Narration|null Null when there is nothing to say.
     *
     * @throws VoiceoverException
     */
    public function narrate(PostDraft $draft, array $scenes, ?array $lines = null): ?Narration
    {
        return $this->narrateItems($draft->brand, $draft->contentItems, $draft->title, $scenes, $lines);
    }

    /**
     * The same for items that are not (yet) a draft — what `hub:render-video` narrates.
     *
     * @param  Collection<int, ContentItem>  $items
     * @param  list<Scene>  $scenes
     * @param  list<string>|null  $lines
     *
     * @throws VoiceoverException
     */
    public function narrateItems(Brand $brand, Collection $items, ?string $title, array $scenes, ?array $lines = null): ?Narration
    {
        $script = $lines === null
            ? $this->builder->build($brand, $scenes, $items, $title)
            : $this->builder->fromLines($scenes, $lines);

        // The headline of a roundup is the brand's own text, on the cover and in the caption as it stands.
        return $this->speak($brand, $script, $items, trusted: [(string) $title]);
    }

    /**
     * Lines that belong to no item — a storyboard's approved copy. They are held to the rules that do not
     * depend on an item (no web addresses, the character budget) but cannot be checked against amounts.
     *
     * @param  list<Scene>  $scenes
     * @param  list<string>  $lines
     *
     * @throws VoiceoverException
     */
    public function narrateLines(Brand $brand, array $scenes, array $lines): ?Narration
    {
        return $this->speak($brand, $this->builder->fromLines($scenes, $lines), null);
    }

    /**
     * @param  Collection<int, ContentItem>|null  $items  What every figure must come from; null skips that check.
     * @param  list<string>  $trusted  Brand copy the script repeats (see ScriptGuard::check).
     *
     * @throws VoiceoverException
     */
    private function speak(Brand $brand, Script $script, ?Collection $items, array $trusted = []): ?Narration
    {
        $settings = $brand->voiceoverSettings();

        if (! $settings->canSpeak()) {
            throw new VoiceoverException('Brend nema odabran glas ili ELEVENLABS_API_KEY nije postavljen.', 'not_configured');
        }

        $violations = $this->guard->check($script, $items ?? [], amounts: $items !== null, trusted: $trusted);

        if ($violations !== []) {
            throw new VoiceoverException('Tekst voice-overa nije prošao provjeru: '.implode(' ', $violations), 'script_rejected');
        }

        $spoken = array_map(fn (string $text): string => $this->spoken->speak($text, $settings->pronunciations), $script->texts());

        if (array_all($spoken, fn (string $text): bool => $text === '')) {
            return null;
        }

        $clips = [];

        foreach ($spoken as $text) {
            $clips[] = $text === '' ? null : NarrationClip::from($this->synthesizer->clip($text, $settings, $brand));
        }

        return new Narration($clips, $script, $settings->musicGainDb(), (string) $settings->voiceId, $settings->model);
    }
}
