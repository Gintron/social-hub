<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SuggestTopics;
use App\Ai\OpenAiException;
use App\Models\Brand;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Topics a person asked for from the panel: the model takes longer than a page should wait, so it is asked here and
 * the list fills in when the answer comes.
 */
final class SuggestTopicsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $brandId, public readonly bool $more = false) {}

    public function uniqueId(): string
    {
        return $this->brandId.($this->more ? ':more' : '');
    }

    public function handle(SuggestTopics $topics): void
    {
        $brand = Brand::query()->find($this->brandId);

        if ($brand === null) {
            return;
        }

        try {
            $topics->execute($brand, more: $this->more);
        } catch (InvalidArgumentException|OpenAiException $e) {
            Log::warning('AI lik: teme nisu predložene', ['brand' => $brand->slug, 'error' => $e->getMessage()]);
        }
    }
}
