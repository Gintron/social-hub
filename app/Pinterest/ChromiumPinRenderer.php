<?php

declare(strict_types=1);

namespace App\Pinterest;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Rendering\ImageRenderer;

final class ChromiumPinRenderer implements PinImageRenderer
{
    public const TEMPLATE = 'kinds/recipe-pin';

    public function __construct(private readonly ImageRenderer $renderer) {}

    public function render(Brand $brand, array $viewModel): MediaAsset
    {
        return $this->renderer->render($brand, self::TEMPLATE, $viewModel);
    }
}
