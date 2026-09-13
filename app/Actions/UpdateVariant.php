<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PostVariant;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Edit one channel's text, on/off switch and options. Format has its own action because changing
 * it also changes the media (ChangeVariantFormat).
 */
final class UpdateVariant
{
    /**
     * Settings a person or the agent may set here; anything else is the hub's own bookkeeping.
     */
    public const EDITABLE_SETTINGS = [
        'delivery', 'privacy_level', 'disable_comment', 'disable_duet', 'disable_stitch',
        'auto_add_music', 'first_comment', 'share_to_feed',
    ];

    /**
     * @param  array<string, mixed>  $settings
     */
    public function execute(PostVariant $variant, ?string $caption = null, ?bool $enabled = null, array $settings = []): PostVariant
    {
        if ($variant->isLocked()) {
            throw new InvalidArgumentException("Varijanta je {$variant->status->label()} i više se ne mijenja.");
        }

        if ($caption !== null) {
            $variant->caption = $caption;
        }

        if ($enabled !== null) {
            $variant->enabled = $enabled;
        }

        $variant->save();

        // Key by key: the model may have been loaded while a render was running, and writing its
        // whole settings array back would restore that render's "rendering" mark.
        $variant->putSettings(Arr::only($settings, self::EDITABLE_SETTINGS));

        return $variant;
    }
}
