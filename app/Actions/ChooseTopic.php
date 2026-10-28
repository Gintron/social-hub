<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TopicStatus;
use App\Jobs\WriteTopicScriptJob;
use App\Models\TopicIdea;
use InvalidArgumentException;

/**
 * A person picks a topic and its script is written in the background (WriteTopicScriptJob). With `$rewrite` a topic
 * that already has a script gets a new one, which replaces the old one and any edits to it.
 */
final class ChooseTopic
{
    public function execute(TopicIdea $idea, bool $rewrite = false): TopicIdea
    {
        if (! $idea->status->canBeChosen() && ! ($rewrite && $idea->status->hasScript())) {
            throw new InvalidArgumentException("Tema #{$idea->id} je „{$idea->status->label()}“ i ne može se odabrati.");
        }

        // The status goes first: a sync queue runs the job inside dispatch(), and it would be overwritten afterwards.
        $idea->forceFill([
            'status' => TopicStatus::Writing,
            'chosen_at' => now(),
            'script_error' => null,
            'approved_at' => null,
            'approved_by' => null,
        ])->save();

        WriteTopicScriptJob::dispatch($idea->id);

        return $idea->refresh();
    }
}
