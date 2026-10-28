<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\OpenAiException;
use App\Enums\TopicStatus;
use App\Models\TopicIdea;
use App\Persona\ScriptWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

/**
 * Writes the script of a topic a person picked (ChooseTopic). A model that is busy is asked again; one that cannot
 * answer (no key, no credit, a refusal) leaves the topic `failed` with the reason, and the person can pick it again.
 */
final class WriteTopicScriptJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $ideaId) {}

    public function uniqueId(): string
    {
        return (string) $this->ideaId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ScriptWriter $writer): void
    {
        $idea = TopicIdea::query()->with('brand')->find($this->ideaId);

        if ($idea === null || $idea->status !== TopicStatus::Writing) {
            return;
        }

        try {
            $answer = $writer->write($idea, $idea->brand->personaSettings());
        } catch (OpenAiException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 120);

                return;
            }

            $this->markFailed($idea, $e->getMessage());

            return;
        } catch (InvalidArgumentException $e) {
            $this->markFailed($idea, 'Model nije vratio upotrebljivu skriptu: '.$e->getMessage());

            return;
        }

        $idea->forceFill([
            'status' => TopicStatus::Scripted,
            'script' => $answer['script']->toArray(),
            'script_error' => null,
            'scripted_at' => now(),
            'model' => $answer['model'],
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        $idea = TopicIdea::query()->find($this->ideaId);

        if ($idea?->status === TopicStatus::Writing) {
            $this->markFailed($idea, 'Skripta nije napisana: '.mb_substr((string) $e?->getMessage(), 0, 300));
        }
    }

    private function markFailed(TopicIdea $idea, string $reason): void
    {
        $idea->forceFill(['status' => TopicStatus::Failed, 'script_error' => $reason])->save();
    }
}
