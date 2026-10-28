<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TopicStatus;
use App\Models\TopicIdea;
use InvalidArgumentException;

/**
 * A person sets a proposal aside. It stays on the list of what the character already had, so it is not offered again.
 */
final class RejectTopic
{
    public function execute(TopicIdea $idea): TopicIdea
    {
        if (! in_array($idea->status, [TopicStatus::Proposed, TopicStatus::Failed], true)) {
            throw new InvalidArgumentException("Tema #{$idea->id} je „{$idea->status->label()}“ i ne može se odbaciti.");
        }

        $idea->forceFill(['status' => TopicStatus::Rejected])->save();

        return $idea;
    }
}
