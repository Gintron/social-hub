<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Models\PostVariant;

/**
 * What stands between a variant and publishing, for a person to read: the media (FormatCheck) or the words
 * (RestrictedGoodsCheck). One answer for every screen, so a new check is added here and nowhere else.
 */
final class PublishProblem
{
    public function __construct(
        private readonly FormatCheck $formats,
        private readonly RestrictedGoodsCheck $goods,
    ) {}

    /**
     * null when the variant is ready. A channel a person posts by hand needs no media, but the words still count.
     */
    public function of(PostVariant $variant): ?string
    {
        if ($variant->platform->isManual()) {
            return $this->goods->variantProblem($variant);
        }

        return $this->formats->problem($variant) ?? $this->goods->variantProblem($variant);
    }
}
