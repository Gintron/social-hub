<?php

declare(strict_types=1);

namespace Tests\Feature\Persona;

use App\Actions\SuggestTopics;
use App\Ai\OpenAiException;
use App\Enums\TopicStatus;
use App\Models\Brand;
use App\Models\TopicIdea;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * A character's topics of the day: asked once a day from its description, with what it already had, and an answer that
 * repeats a topic anyway is not kept. The model is faked; the request is the real one.
 */
final class SuggestTopicsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.api_key', 'test-key');
        config()->set('openai.model', 'gpt-6-sol');
        config()->set('openai.persona.model', null);
        Sleep::fake();
        CarbonImmutable::setTestNow('2026-10-28 08:00:00 Europe/Zagreb');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_topics_are_written_from_the_character_and_kept_for_its_local_day(): void
    {
        $brand = Brand::factory()->persona(['topics_per_day' => 2])->create();
        TopicIdea::factory()->for($brand)->create(['title' => 'The 50/30/20 budget', 'for_date' => '2026-10-20']);
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['topics' => [
            $this->topic('Wait 24 hours before you buy'),
            $this->topic('Why your bank app makes you spend'),
            $this->topic('A third one the day did not ask for'),
        ]]))]);

        $ideas = app(SuggestTopics::class)->execute($brand);

        $this->assertSame(['Wait 24 hours before you buy', 'Why your bank app makes you spend'], $ideas->pluck('title')->all());
        $this->assertTrue($ideas->every(fn (TopicIdea $idea): bool => $idea->status === TopicStatus::Proposed
            && $idea->for_date->toDateString() === '2026-10-28' && $idea->model === 'gpt-6-sol-2026-09-01'));

        Http::assertSent(function (Request $request): bool {
            $system = $request->data()['input'][0]['content'];
            $user = json_decode($request->data()['input'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

            return $request->data()['text']['format']['name'] === 'topic_ideas'
                && str_contains($system, 'Who they are: A calm money coach')
                && str_contains($system, 'Never talk about: Crypto')
                && str_contains($system, 'Language of everything you write: English')
                && $user['topics_wanted'] === 2
                && $user['weekday'] === 'Wednesday'
                && $user['recent_topics'] === ['The 50/30/20 budget'];
        });
    }

    public function test_the_day_is_asked_once_and_more_adds_a_batch(): void
    {
        $brand = Brand::factory()->persona(['topics_per_day' => 1])->create();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(FakeOpenAi::answer(['topics' => [$this->topic('First')]]))
            ->push(FakeOpenAi::answer(['topics' => [$this->topic('Second')]]))]);

        app(SuggestTopics::class)->execute($brand);
        $again = app(SuggestTopics::class)->execute($brand);

        $this->assertSame(['First'], $again->pluck('title')->all());
        Http::assertSentCount(1);

        $more = app(SuggestTopics::class)->execute($brand, more: true);

        $this->assertSame(['Second'], $more->pluck('title')->all());
        $this->assertSame(2, $brand->topicIdeas()->count());
    }

    public function test_a_topic_the_character_already_had_is_not_offered_again(): void
    {
        $brand = Brand::factory()->persona()->create();
        TopicIdea::factory()->for($brand)->create(['title' => 'Wait 24 hours before you buy!', 'for_date' => '2026-10-01', 'status' => TopicStatus::Rejected]);
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['topics' => [
            $this->topic('wait 24 hours before you buy'),
            $this->topic('Cancel one subscription today'),
            $this->topic('Cancel one subscription today.'),
            ['title' => 'No hook', 'hook' => '', 'angle' => 'x', 'reason' => ''],
        ]]))]);

        $ideas = app(SuggestTopics::class)->execute($brand);

        $this->assertSame(['Cancel one subscription today'], $ideas->pluck('title')->all());
    }

    public function test_a_day_whose_answer_held_nothing_new_is_not_asked_again(): void
    {
        $brand = Brand::factory()->persona()->create();
        TopicIdea::factory()->for($brand)->create(['title' => 'Wait 24 hours', 'for_date' => '2026-10-20']);
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['topics' => [$this->topic('Wait 24 hours')]]))]);

        $this->assertCount(0, app(SuggestTopics::class)->execute($brand));
        $this->assertCount(0, app(SuggestTopics::class)->execute($brand));

        Http::assertSentCount(1);

        // The next day is a new day.
        CarbonImmutable::setTestNow('2026-10-29 08:00:00 Europe/Zagreb');
        app(SuggestTopics::class)->execute($brand);
        Http::assertSentCount(2);
    }

    public function test_a_brand_that_is_not_a_described_character_is_not_asked_about(): void
    {
        Http::fake();

        foreach ([Brand::factory()->create(), Brand::factory()->persona(['enabled' => false])->create(), Brand::factory()->persona(['goal' => ''])->create()] as $brand) {
            try {
                app(SuggestTopics::class)->execute($brand);
                $this->fail("{$brand->name} was asked about");
            } catch (InvalidArgumentException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_model_that_cannot_answer_leaves_no_topics(): void
    {
        $brand = Brand::factory()->persona()->create();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 429)]);

        try {
            app(SuggestTopics::class)->execute($brand);
            $this->fail('No exception');
        } catch (OpenAiException $e) {
            $this->assertSame('quota_exceeded', $e->errorCode);
        }

        $this->assertSame(0, TopicIdea::query()->count());
    }

    /**
     * @return array{title: string, hook: string, angle: string, reason: string}
     */
    private function topic(string $title): array
    {
        return ['title' => $title, 'hook' => "Hook for {$title}", 'angle' => "What {$title} says.", 'reason' => 'Fits the channel.'];
    }
}
