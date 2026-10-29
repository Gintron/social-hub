<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\OpenAiException;
use App\Enums\TopicStatus;
use App\Models\Brand;
use App\Models\TopicIdea;
use App\Persona\TopicSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Today's topics of an AI character: asked for once per local day (the morning run, or a person in the panel), and
 * `$more` asks for another batch on top. What the character already had in the last weeks goes with the question.
 */
final class SuggestTopics
{
    public function __construct(private readonly TopicSuggester $suggester) {}

    /**
     * @return Collection<int, TopicIdea> The day's topics: the ones already there, or the ones just made.
     *
     * @throws InvalidArgumentException When the brand is not a character described enough to write from.
     * @throws OpenAiException
     * @throws LockTimeoutException When another ask for the same character took longer than `hub.persona.lock_wait_seconds`.
     */
    public function execute(Brand $brand, ?CarbonImmutable $day = null, bool $more = false): Collection
    {
        $persona = $brand->personaSettings();

        if (! $persona->isReady()) {
            throw new InvalidArgumentException("Brend „{$brand->name}“ nije AI lik ili lik nema opis i cilj.");
        }

        $day = ($day ?? CarbonImmutable::now($brand->timezone ?: 'Europe/Zagreb'))->startOfDay();

        $asked = "persona-topics-asked:{$brand->id}:{$day->toDateString()}";

        // The morning run and a person in the panel may ask at the same moment; one of them asks OpenAI.
        return Cache::lock("persona-topics:{$brand->id}", 180)->block((int) config('hub.persona.lock_wait_seconds', 150), function () use ($brand, $persona, $day, $more, $asked): Collection {
            $existing = $brand->topicIdeas()->whereDate('for_date', $day->toDateString())->orderBy('id')->get();

            // A day whose answer held nothing new is asked, too: the hourly run would otherwise pay for it every hour.
            if (! $more && ($existing->isNotEmpty() || Cache::has($asked))) {
                return $existing;
            }

            $recent = $brand->topicIdeas()
                ->whereDate('for_date', '>=', $day->subDays((int) config('hub.persona.recent_days', 60))->toDateString())
                ->latest('id')
                ->limit(150)
                ->pluck('title')
                ->all();

            $answer = $this->suggester->suggest($persona, $day, $recent, $persona->topicsPerDay);
            Cache::put($asked, true, $day->endOfDay());

            return collect($answer['topics'])->map(fn (array $topic): TopicIdea => $brand->topicIdeas()->create([
                ...$topic,
                'for_date' => $day->toDateString(),
                'status' => TopicStatus::Proposed,
                'model' => $answer['model'],
            ]));
        });
    }
}
