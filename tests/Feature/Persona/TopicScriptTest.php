<?php

declare(strict_types=1);

namespace Tests\Feature\Persona;

use App\Actions\ApproveTopicScript;
use App\Actions\ChooseTopic;
use App\Actions\RejectTopic;
use App\Actions\UpdateTopicScript;
use App\Enums\TopicStatus;
use App\Models\Brand;
use App\Models\TopicIdea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * From a picked topic to an approved script: written in the background, read and changed by a person, approved. An
 * approved script that changes is read again; a script that does not come says why.
 */
final class TopicScriptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.api_key', 'test-key');
        config()->set('hub.persona.max_seconds', 45);
        Sleep::fake();
    }

    public function test_a_picked_topic_gets_its_script_from_the_character_and_the_topic(): void
    {
        $idea = TopicIdea::factory()->create(['title' => 'Wait 24 hours', 'hook' => 'Stop before you pay.']);
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer($this->script()))]);

        app(ChooseTopic::class)->execute($idea);

        $idea->refresh();
        $this->assertSame(TopicStatus::Scripted, $idea->status);
        $this->assertSame('Stop. Wait a day.', $idea->shortScript()?->lines[0]['text']);
        $this->assertSame('broll', $idea->shortScript()?->lines[1]['shot']);
        $this->assertNotNull($idea->chosen_at);
        $this->assertNotNull($idea->scripted_at);

        Http::assertSent(function (Request $request): bool {
            $user = json_decode($request->data()['input'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

            return $request->data()['text']['format']['name'] === 'short_script'
                && str_contains($request->data()['input'][0]['content'], 'Name: Mia')
                && $user['topic'] === 'Wait 24 hours'
                && $user['hook'] === 'Stop before you pay.'
                && $user['max_spoken_seconds'] === 45
                && $user['max_spoken_words'] === 117;
        });
    }

    public function test_a_script_that_does_not_come_says_why_and_the_topic_can_be_picked_again(): void
    {
        $idea = TopicIdea::factory()->create();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(FakeOpenAi::answer(['title' => 'x', 'lines' => [], 'caption' => '', 'hashtags' => '']))
            ->push(FakeOpenAi::answer($this->script()))]);

        app(ChooseTopic::class)->execute($idea);

        $idea->refresh();
        $this->assertSame(TopicStatus::Failed, $idea->status);
        $this->assertStringContainsString('nema nijedan redak', (string) $idea->script_error);

        app(ChooseTopic::class)->execute($idea);

        $this->assertSame(TopicStatus::Scripted, $idea->refresh()->status);
        $this->assertNull($idea->script_error);
    }

    public function test_an_approved_script_that_changes_goes_back_to_review(): void
    {
        $user = User::factory()->create();
        $idea = TopicIdea::factory()->scripted()->create();

        app(ApproveTopicScript::class)->execute($idea, $user->id);
        $this->assertSame(TopicStatus::Approved, $idea->status);

        // Saving what is there changes nothing, so the approval stays.
        app(UpdateTopicScript::class)->execute($idea, $idea->script);
        $this->assertSame(TopicStatus::Approved, $idea->refresh()->status);

        $edited = $idea->script;
        $edited['lines'][0]['text'] = 'Hold on. Wait a day first.';
        app(UpdateTopicScript::class)->execute($idea, $edited);

        $idea->refresh();
        $this->assertSame(TopicStatus::Scripted, $idea->status);
        $this->assertNull($idea->approved_at);
        $this->assertSame('Hold on. Wait a day first.', $idea->shortScript()?->lines[0]['text']);
    }

    public function test_a_script_cannot_be_emptied_or_approved_before_it_exists(): void
    {
        $scripted = TopicIdea::factory()->scripted()->create();

        $this->assertThrows(fn () => app(UpdateTopicScript::class)->execute($scripted, ['title' => 'x', 'lines' => [['text' => ' ']]]), InvalidArgumentException::class);
        $this->assertThrows(fn () => app(ApproveTopicScript::class)->execute(TopicIdea::factory()->create(), null), InvalidArgumentException::class);
        $this->assertThrows(fn () => app(ChooseTopic::class)->execute($scripted), InvalidArgumentException::class);
    }

    public function test_a_rewrite_replaces_the_script_and_its_approval(): void
    {
        $idea = TopicIdea::factory()->scripted()->create();
        app(ApproveTopicScript::class)->execute($idea, null);
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer($this->script()))]);

        app(ChooseTopic::class)->execute($idea, rewrite: true);

        $idea->refresh();
        $this->assertSame(TopicStatus::Scripted, $idea->status);
        $this->assertNull($idea->approved_at);
        $this->assertSame('Stop. Wait a day.', $idea->shortScript()?->lines[0]['text']);
    }

    public function test_a_rejected_topic_stays_rejected_and_can_be_picked_later(): void
    {
        $idea = TopicIdea::factory()->for(Brand::factory()->persona())->create();

        app(RejectTopic::class)->execute($idea);
        $this->assertSame(TopicStatus::Rejected, $idea->status);
        $this->assertTrue($idea->status->canBeChosen());
        $this->assertThrows(fn () => app(RejectTopic::class)->execute(TopicIdea::factory()->scripted()->create()), InvalidArgumentException::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function script(): array
    {
        return [
            'title' => 'Wait 24 hours before you buy',
            'lines' => [
                ['text' => 'Stop. Wait a day.', 'shot' => 'talking', 'visual' => 'raises a hand'],
                ['text' => 'Most of what you want tonight, you will not want tomorrow.', 'shot' => 'broll', 'visual' => 'a cart full of boxes'],
                ['text' => 'What did you skip this week?', 'shot' => 'talking', 'visual' => 'leans in'],
            ],
            'caption' => 'The 24-hour rule.',
            'hashtags' => '#money #habits',
        ];
    }
}
