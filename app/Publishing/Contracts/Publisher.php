<?php

declare(strict_types=1);

namespace App\Publishing\Contracts;

use App\Enums\Platform;
use App\Models\PostVariant;
use App\Publishing\PublishResult;

interface Publisher
{
    public function supports(Platform $platform): bool;

    /**
     * @throws \App\Publishing\Exceptions\PublishException
     */
    public function publish(PostVariant $variant): PublishResult;
}
