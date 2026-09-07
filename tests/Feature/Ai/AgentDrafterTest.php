<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AgentDrafter;
use App\Ai\CaptionWriter;
use App\Ai\CaptionWriterException;
use App\Ai\FakeCaptionWriter;
use App\Enums\ActorType;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Notifications\DraftsAwaitingApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AgentDrafterTest extends TestCase
{
    use RefreshDatabase;

    private FakeCaptionWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config()->set('hub.admin_emails', ['ops@example.test']);
        config()->set('hub.ai.api_key', 'test-key');

        $this->writer = new FakeCaptionWriter;
        $this->app->instance(CaptionWriter::class, $this->writer);
    }

    public function test_it_drafts_candidates_and_leaves_them_for_approval(): void
    {
        [$brand] = $this->brandWithItems(2);
        SocialAccount::factory()->for($brand)->create();
        SocialAccount::factory()->for($brand)->instagram()->create();

        $this->writer->queue(
            FakeCaptionWriter::captions(facebook: 'Prvi FB tekst.', instagram: 'Prvi IG tekst.', hashtags: '#posao #split'),
            FakeCaptionWriter::captions(facebook: 'Drugi FB tekst.', instagram: 'Drugi IG tekst.', hashtags: '#posao'),
        );

        $result = app(AgentDrafter::class)->run($brand, limit: 2);

        $this->assertSame(2, $result['drafted']);
        $this->assertSame(0, $result['fallbacks']);

        $drafts = PostDraft::query()->with('variants')->get();
        $this->assertCount(2, $drafts);
        $this->assertTrue($drafts->every(fn (PostDraft $d): bool => $d->status === DraftStatus::PendingApproval));
        $this->assertTrue($drafts->every(fn (PostDraft $d): bool => $d->created_by_type === ActorType::Agent));

        $first = $drafts->firstWhere('title', 'Oglas 1');
        $this->assertSame('Prvi FB tekst.', $first->variants->firstWhere('platform', Platform::FacebookPage)->caption);
        $this->assertSame("Prvi IG tekst.\n\n#posao #split", $first->variants->firstWhere('platform', Platform::InstagramBusiness)->caption);

        // The agent never publishes; every draft is linked to the run that produced it.
        $run = AgentRun::query()->firstOrFail();
        $this->assertSame('done', $run->status);
        $this->assertSame(200, $run->input_tokens);
        $this->assertSame($run->id, $first->agent_run_id);

        Notification::assertSentOnDemand(DraftsAwaitingApproval::class);
    }

    public function test_a_rejected_caption_is_rewritten_once_with_the_reason(): void
    {
        [$brand, $items] = $this->brandWithItems(1);
        SocialAccount::factory()->for($brand)->create();

        $this->writer->queue(
            FakeCaptionWriter::captions(facebook: 'Satnica čak 99,00 €/H!', instagram: 'Sjajno.'),
            FakeCaptionWriter::captions(facebook: 'Satnica 7,00 €/H.', instagram: 'Satnica 7,00 €/H.'),
        );

        $result = app(AgentDrafter::class)->run($brand, limit: 1);

        $this->assertSame(1, $result['drafted']);
        $this->assertSame(0, $result['fallbacks']);
        $this->assertCount(2, $this->writer->calls);
        $this->assertSame([], $this->writer->calls[0]['violations']);
        $this->assertStringContainsString('99,00 €', implode(' ', $this->writer->calls[1]['violations']));

        $caption = PostDraft::query()->firstOrFail()->variants()->firstOrFail()->caption;
        $this->assertStringContainsString('7,00 €/H', $caption);
    }

    public function test_it_falls_back_to_the_deterministic_caption_when_the_model_keeps_inventing(): void
    {
        [$brand] = $this->brandWithItems(1);
        SocialAccount::factory()->for($brand)->create();

        $this->writer->queue(
            FakeCaptionWriter::captions(facebook: 'Plaća 99,00 €!', instagram: 'Ok.'),
            FakeCaptionWriter::captions(facebook: 'Plaća 88,00 €!', instagram: 'Ok.'),
        );

        $result = app(AgentDrafter::class)->run($brand, limit: 1);

        $this->assertSame(1, $result['drafted']);
        $this->assertSame(1, $result['fallbacks']);

        $caption = PostDraft::query()->firstOrFail()->variants()->firstOrFail()->caption;
        $this->assertStringNotContainsString('99,00', $caption);
        $this->assertStringNotContainsString('88,00', $caption);
        $this->assertStringContainsString('Oglas 1', $caption);

        $output = AgentRun::query()->firstOrFail()->output;
        $this->assertTrue($output[0]['fallback']);
    }

    public function test_a_failing_model_does_not_take_the_whole_run_down(): void
    {
        [$brand] = $this->brandWithItems(2);
        SocialAccount::factory()->for($brand)->create();

        $this->writer->queue(
            new CaptionWriterException('Claude API: 529 overloaded'),
            FakeCaptionWriter::captions(facebook: 'Drugi je prošao.', instagram: 'Drugi.'),
        );

        $result = app(AgentDrafter::class)->run($brand, limit: 2);

        $this->assertSame(1, $result['drafted']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, PostDraft::query()->count());
        $this->assertSame('done', AgentRun::query()->firstOrFail()->status);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        [$brand] = $this->brandWithItems(1);
        SocialAccount::factory()->for($brand)->create();

        $result = app(AgentDrafter::class)->run($brand, limit: 1, dryRun: true);

        $this->assertSame(1, $result['drafted']);
        $this->assertSame(0, PostDraft::query()->count());
        Notification::assertNothingSent();
    }

    public function test_items_that_already_have_a_post_are_skipped(): void
    {
        [$brand, $items] = $this->brandWithItems(2);
        SocialAccount::factory()->for($brand)->create();

        PostDraft::factory()->create(['brand_id' => $brand->id])->contentItems()->attach($items->first()->id, ['position' => 0]);

        $result = app(AgentDrafter::class)->run($brand, limit: 5);

        $this->assertSame(1, $result['drafted']);
        $this->assertSame(['Oglas 2'], $result['titles']);
    }

    public function test_the_command_runs_only_brands_that_opted_in(): void
    {
        [$on] = $this->brandWithItems(1, slug: 'ukljucen', voiceEnabled: true);
        [$off] = $this->brandWithItems(1, slug: 'iskljucen', voiceEnabled: false);
        SocialAccount::factory()->for($on)->create();
        SocialAccount::factory()->for($off)->create();

        $this->artisan('hub:agent-draft', ['--limit' => 5])
            ->expectsOutputToContain($on->name)
            ->assertSuccessful();

        $this->assertSame(1, PostDraft::query()->count());
        $this->assertSame($on->id, PostDraft::query()->firstOrFail()->brand_id);
    }

    public function test_the_command_refuses_without_an_api_key(): void
    {
        config()->set('hub.ai.api_key', null);

        $this->artisan('hub:agent-draft')
            ->expectsOutputToContain('ANTHROPIC_API_KEY')
            ->assertFailed();
    }

    /**
     * @return array{0: Brand, 1: \Illuminate\Support\Collection<int, ContentItem>}
     */
    private function brandWithItems(int $count, string $slug = 'studentski-poslovi', bool $voiceEnabled = true): array
    {
        $brand = Brand::factory()->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'site_url' => "https://{$slug}.test",
            'voice' => ['tone' => 'jasan', 'hashtags' => ['posao'], 'agent_enabled' => $voiceEnabled],
        ]);

        $source = Source::factory()->for($brand)->create();

        $items = collect(range(1, $count))->map(fn (int $i): ContentItem => ContentItem::factory()->for($source)->for($brand)->create([
            'kind' => ContentKind::Job,
            'title' => "Oglas {$i}",
            'priority' => 100 - $i,
            'url' => "https://{$slug}.test/posao/{$i}",
            'cta' => ['label' => 'Prijavi se', 'url' => "https://{$slug}.test/posao/{$i}"],
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7,00 €/H']],
            'price' => ['current_cents' => 700, 'old_cents' => null, 'discount_pct' => null, 'currency' => 'EUR', 'unit_label' => '€/H'],
        ]));

        return [$brand, $items];
    }
}
