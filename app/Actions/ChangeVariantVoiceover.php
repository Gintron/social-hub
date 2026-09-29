<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ContentFormat;
use App\Models\PostVariant;
use InvalidArgumentException;

/**
 * Turn the narration of one channel's video on or off, and give the channel the video that has it (or
 * has not). The other channels of the draft keep theirs.
 *
 * A draft may hold both: the video with a voice and the one without are separate assets, so switching
 * a channel back and forth renders nothing and speaks nothing new — it only picks the one that exists.
 */
final class ChangeVariantVoiceover
{
    public function __construct(private readonly PrepareVariantMedia $media) {}

    public function execute(PostVariant $variant, bool $voiceover): PostVariant
    {
        if ($variant->isLocked()) {
            throw new InvalidArgumentException("Kanal je {$variant->status->label()}; voice-over se više ne mijenja.");
        }

        if ($variant->format() !== ContentFormat::Video) {
            throw new InvalidArgumentException('Voice-over postoji samo za video (Reel); promijeni format kanala u video.');
        }

        $variant->loadMissing('draft.brand');

        if ($voiceover && ! $variant->draft?->brand?->voiceoverSettings()->canSpeak()) {
            throw new InvalidArgumentException('Brend nema odabran glas ili ELEVENLABS_API_KEY nije postavljen (Brendovi → Voice-over).');
        }

        $variant->putSettings(['voiceover' => $voiceover ? 'on' : 'off']);

        $this->media->execute([$variant]);

        return $variant->refresh();
    }
}
