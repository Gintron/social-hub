<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a topic of an AI character stands: proposed by the model, picked by a person and written into a script, the
 * script read and approved, the short made and handed to a post. Nothing is paid for beyond text until a person asks
 * for the video of an approved script.
 */
enum TopicStatus: string implements HasColor, HasLabel
{
    case Proposed = 'proposed';
    case Writing = 'writing';
    case Scripted = 'scripted';
    case Approved = 'approved';
    case Producing = 'producing';
    case Produced = 'produced';
    case VideoFailed = 'video_failed';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Prijedlog',
            self::Writing => 'Piše se skripta',
            self::Scripted => 'Skripta čeka pregled',
            self::Approved => 'Skripta odobrena',
            self::Producing => 'Video se radi',
            self::Produced => 'Video je u objavi',
            self::VideoFailed => 'Video nije uspio',
            self::Failed => 'Skripta nije uspjela',
            self::Rejected => 'Odbačeno',
        };
    }

    /**
     * A person may pick it (again): a proposal, one they set aside, or one whose script did not come.
     */
    public function canBeChosen(): bool
    {
        return in_array($this, [self::Proposed, self::Rejected, self::Failed], true);
    }

    /**
     * There is a script to show.
     */
    public function hasScript(): bool
    {
        return in_array($this, [self::Scripted, self::Approved, self::Producing, self::Produced, self::VideoFailed], true);
    }

    /**
     * The script may still change: not while its video is being made, and not once the video is in a post.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Scripted, self::Approved, self::VideoFailed], true);
    }

    public function canProduce(): bool
    {
        return in_array($this, [self::Approved, self::VideoFailed], true);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed, self::Rejected => 'gray',
            self::Writing, self::Scripted, self::Producing => 'warning',
            self::Approved => 'info',
            self::Produced => 'success',
            self::Failed, self::VideoFailed => 'danger',
        };
    }
}
