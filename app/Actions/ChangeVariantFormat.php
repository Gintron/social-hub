<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ContentFormat;
use App\Models\PostVariant;
use InvalidArgumentException;

/**
 * Switch one channel between image, carousel, video and link, and give it the media that needs.
 * The other channels of the draft keep theirs.
 */
final class ChangeVariantFormat
{
    public function __construct(private readonly PrepareVariantMedia $media) {}

    public function execute(PostVariant $variant, ContentFormat $format): PostVariant
    {
        if ($variant->isLocked()) {
            throw new InvalidArgumentException("Kanal je {$variant->status->label()}; format se više ne mijenja.");
        }

        if (! in_array($format, $variant->platform->formats(), true)) {
            throw new InvalidArgumentException("{$variant->platform->label()} ne podržava format {$format->label()}.");
        }

        // `mode` is where Facebook kept this before formats were one setting for every channel.
        $variant->forgetSetting('mode');
        $variant->putSettings(['format' => $format->value]);

        $this->media->execute([$variant]);

        return $variant->refresh();
    }
}
