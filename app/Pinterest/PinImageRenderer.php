<?php

declare(strict_types=1);

namespace App\Pinterest;

use App\Models\Brand;
use App\Models\MediaAsset;

/**
 * Draws one pin. An interface only so the tests need no Chromium: the real one is ChromiumPinRenderer.
 */
interface PinImageRenderer
{
    /**
     * @param  array<string, mixed>  $viewModel  TemplateData::forItem() plus the pin's own words under `pin`.
     */
    public function render(Brand $brand, array $viewModel): MediaAsset;
}
