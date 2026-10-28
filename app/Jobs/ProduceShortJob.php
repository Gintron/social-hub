<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TopicStatus;
use App\Models\TopicIdea;
use App\Persona\ShortProducer;
use App\Video\FalException;
use App\Voiceover\VoiceoverException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Makes the short of an approved script, a step at a time: the worker stops any job after 180 s and fal takes minutes,
 * so a run does what it can and comes back (`release`) while fal is still at it — until `fal.max_wait_minutes` after the
 * video was asked for. What fal was asked for is written down as it is asked, so coming back never pays twice.
 *
 * A cause no retry can help (no picture of the character, no channel, the caption says what the script does not, the
 * cost is over the ceiling, fal refused) leaves the topic `video_failed` with the reason; a person fixes it and asks again.
 */
final class ProduceShortJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $ideaId)
    {
        $this->onQueue('render');
    }

    public function uniqueId(): string
    {
        return (string) $this->ideaId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('fal.max_wait_minutes', 45));
    }

    public function handle(ShortProducer $producer): void
    {
        $idea = TopicIdea::query()->with('brand')->find($this->ideaId);

        if ($idea === null || $idea->status !== TopicStatus::Producing) {
            return;
        }

        try {
            $draft = $producer->produce($idea);
        } catch (FalException|VoiceoverException $e) {
            if ($e->retryable) {
                $this->release((int) config('fal.poll_seconds', 30) * 2);

                return;
            }

            $this->markFailed($idea, $e->getMessage());

            return;
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->markFailed($idea, $e->getMessage());

            return;
        }

        if ($draft === null) {
            $this->release((int) config('fal.poll_seconds', 30));

            return;
        }

        $idea->forceFill(['status' => TopicStatus::Produced, 'post_draft_id' => $draft->id, 'production_error' => null])->save();
    }

    public function failed(?Throwable $e): void
    {
        $idea = TopicIdea::query()->find($this->ideaId);

        if ($idea?->status === TopicStatus::Producing) {
            $this->markFailed($idea, 'Video nije gotov na vrijeme: '.mb_substr((string) $e?->getMessage(), 0, 300).' Zatraži ga ponovno; gotovi kadrovi se ne plaćaju opet.');
        }
    }

    private function markFailed(TopicIdea $idea, string $reason): void
    {
        $idea->forceFill(['status' => TopicStatus::VideoFailed, 'production_error' => mb_substr($reason, 0, 2000)])->save();
    }
}
