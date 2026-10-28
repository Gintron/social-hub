<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\TopicStatus;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\TopicIdeas\Pages\EditTopicIdea;
use App\Filament\Resources\TopicIdeas\Pages\ListTopicIdeas;
use App\Filament\Resources\TopicIdeas\TopicIdeaResource;
use App\Jobs\ProduceShortJob;
use App\Jobs\SuggestTopicsJob;
use App\Jobs\WriteTopicScriptJob;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\TopicIdea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The character tab on a brand and the topics screen: a person describes the character, picks a topic, edits and
 * approves its script, and the menu shows topics only where a brand is a character.
 */
final class TopicIdeaPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_a_brand_becomes_a_character_in_its_own_tab(): void
    {
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm([
                'persona.enabled' => true,
                'persona.name' => 'Mia',
                'persona.language' => 'en',
                'persona.description' => 'A calm money coach.',
                'persona.goal' => 'Help people save.',
                'persona.topics_per_day' => 4,
                'persona.suggest_at' => '06:30',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $persona = $brand->refresh()->personaSettings();
        $this->assertTrue($persona->isReady());
        $this->assertSame('Mia', $persona->name);
        $this->assertSame(4, $persona->topicsPerDay);
        $this->assertSame('06:30', $persona->suggestAt);
    }

    public function test_a_character_needs_a_description_and_a_goal(): void
    {
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['persona.enabled' => true, 'persona.description' => '', 'persona.goal' => ''])
            ->call('save')
            ->assertHasFormErrors(['persona.description' => 'required', 'persona.goal' => 'required']);
    }

    public function test_the_topics_menu_is_there_only_when_a_brand_is_a_character(): void
    {
        Brand::factory()->create();
        $this->assertFalse(TopicIdeaResource::shouldRegisterNavigation());

        Brand::factory()->persona()->create();
        Once::flush(); // the answer is kept for the request
        $this->assertTrue(TopicIdeaResource::shouldRegisterNavigation());
    }

    public function test_the_list_shows_the_days_topics_and_picking_one_starts_its_script(): void
    {
        Queue::fake();
        $brand = Brand::factory()->persona()->create();
        $ideas = TopicIdea::factory()->count(3)->for($brand)->create();
        $rejected = TopicIdea::factory()->for($brand)->create(['status' => TopicStatus::Rejected]);

        $this->get(TopicIdeaResource::getUrl('index'))->assertOk();

        Livewire::test(ListTopicIdeas::class)
            ->assertCanSeeTableRecords($ideas)
            ->assertCanNotSeeTableRecords([$rejected])
            ->callTableAction('choose', $ideas[0]);

        $this->assertSame(TopicStatus::Writing, $ideas[0]->refresh()->status);
        Queue::assertPushed(WriteTopicScriptJob::class, fn (WriteTopicScriptJob $job): bool => $job->ideaId === $ideas[0]->id);
    }

    public function test_the_list_does_not_ask_the_database_once_per_row(): void
    {
        $brand = Brand::factory()->persona()->create();
        TopicIdea::factory()->count(2)->for($brand)->create();
        $count = fn (): int => $this->queries(fn () => Livewire::test(ListTopicIdeas::class));
        $count();
        $few = $count();

        TopicIdea::factory()->count(8)->for(Brand::factory()->persona())->create();

        $this->assertSame($few, $count());
    }

    public function test_asking_for_topics_from_the_panel_goes_to_the_queue(): void
    {
        Queue::fake();
        $brand = Brand::factory()->persona()->create();

        Livewire::test(ListTopicIdeas::class)
            ->callAction('suggest', ['brand_id' => $brand->id, 'more' => true]);

        Queue::assertPushed(SuggestTopicsJob::class, fn (SuggestTopicsJob $job): bool => $job->brandId === $brand->id && $job->more);
    }

    public function test_a_script_is_edited_and_approved_on_the_topics_page(): void
    {
        $idea = TopicIdea::factory()->scripted()->create();

        $this->get(TopicIdeaResource::getUrl('edit', ['record' => $idea]))->assertOk()->assertSee('Trajanje (procjena)');

        $script = $idea->script;
        $script['lines'][0]['text'] = 'Hold on. Wait a day.';

        Livewire::test(EditTopicIdea::class, ['record' => $idea->getRouteKey()])
            ->fillForm(['script' => $script])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('approve');

        $idea->refresh();
        $this->assertSame(TopicStatus::Approved, $idea->status);
        $this->assertSame('Hold on. Wait a day.', $idea->shortScript()?->lines[0]['text']);
    }

    public function test_an_approved_script_is_made_into_a_video_from_its_page(): void
    {
        Queue::fake();
        $idea = TopicIdea::factory()->scripted()->create(['status' => TopicStatus::Approved]);

        Livewire::test(EditTopicIdea::class, ['record' => $idea->getRouteKey()])
            ->assertActionVisible('produce')
            ->callAction('produce');

        $this->assertSame(TopicStatus::Producing, $idea->refresh()->status);
        Queue::assertPushed(ProduceShortJob::class, fn (ProduceShortJob $job): bool => $job->ideaId === $idea->id);

        // While it is made, and once it is in a post, the script stays as it was.
        Livewire::test(EditTopicIdea::class, ['record' => $idea->getRouteKey()])
            ->assertActionHidden('produce')
            ->assertActionHidden('rewrite');
    }

    public function test_a_topic_whose_video_is_in_a_post_links_to_it(): void
    {
        $draft = PostDraft::factory()->create();
        $idea = TopicIdea::factory()->scripted()->create(['status' => TopicStatus::Produced, 'post_draft_id' => $draft->id]);

        $this->get(TopicIdeaResource::getUrl('edit', ['record' => $idea]))->assertOk()->assertSee('Video je u objavi');

        Livewire::test(EditTopicIdea::class, ['record' => $idea->getRouteKey()])
            ->assertActionVisible('open_post');
    }

    public function test_a_topic_without_a_script_has_nothing_to_save(): void
    {
        $idea = TopicIdea::factory()->create(['status' => TopicStatus::Failed, 'script_error' => 'OpenAI 429: no credit']);

        $this->get(TopicIdeaResource::getUrl('edit', ['record' => $idea]))->assertOk()->assertSee('OpenAI 429: no credit');

        Livewire::test(EditTopicIdea::class, ['record' => $idea->getRouteKey()])
            ->assertActionVisible('choose')
            ->assertActionHidden('approve');
    }

    private function queries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
