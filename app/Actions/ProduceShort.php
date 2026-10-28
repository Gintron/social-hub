<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TopicStatus;
use App\Jobs\ProduceShortJob;
use App\Models\TopicIdea;
use InvalidArgumentException;

/**
 * A person asks for the video of an approved script. It is made in the background (ProduceShortJob → ShortProducer)
 * and ends as a post waiting for approval on the character's channels. Asking again after a failure reuses every shot
 * fal already made.
 */
final class ProduceShort
{
    public function execute(TopicIdea $idea): TopicIdea
    {
        if (! $idea->status->canProduce()) {
            throw new InvalidArgumentException("Tema #{$idea->id} je „{$idea->status->label()}“; video se radi samo iz odobrene skripte.");
        }

        // The status goes first: a sync queue runs the job inside dispatch(), and it would be overwritten afterwards.
        $idea->forceFill(['status' => TopicStatus::Producing, 'production_error' => null])->save();

        ProduceShortJob::dispatch($idea->id);

        return $idea->refresh();
    }
}
