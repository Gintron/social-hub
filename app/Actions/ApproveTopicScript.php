<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TopicStatus;
use App\Models\TopicIdea;
use InvalidArgumentException;

/**
 * A person has read the script and it may become a short. Only an approved script is ever voiced or filmed.
 */
final class ApproveTopicScript
{
    public function execute(TopicIdea $idea, ?int $approverId): TopicIdea
    {
        if ($idea->status !== TopicStatus::Scripted || $idea->shortScript() === null) {
            throw new InvalidArgumentException("Tema #{$idea->id} nema skriptu koja čeka pregled.");
        }

        $idea->forceFill([
            'status' => TopicStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $approverId,
        ])->save();

        return $idea;
    }
}
