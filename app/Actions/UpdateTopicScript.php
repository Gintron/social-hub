<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TopicStatus;
use App\Models\TopicIdea;
use App\Persona\ShortScript;
use InvalidArgumentException;

/**
 * A person's edit of a script. An approved script that changes is a script to read again, so it goes back to review.
 */
final class UpdateTopicScript
{
    /**
     * @param  array<string, mixed>  $script
     *
     * @throws InvalidArgumentException When the script cannot change now (none yet, its video is being made or is in a post), or the edit leaves nothing a short is made of.
     */
    public function execute(TopicIdea $idea, array $script): TopicIdea
    {
        if (! $idea->status->isEditable()) {
            throw new InvalidArgumentException("Skripta teme #{$idea->id} se ne može mijenjati ({$idea->status->label()}).");
        }

        $new = ShortScript::fromArray($script)->toArray();

        if ($new === $idea->shortScript()?->toArray()) {
            return $idea;
        }

        $idea->forceFill([
            'script' => $new,
            'status' => TopicStatus::Scripted,
            'approved_at' => null,
            'approved_by' => null,
        ])->save();

        return $idea;
    }
}
