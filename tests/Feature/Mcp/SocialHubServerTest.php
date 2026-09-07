<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\VariantStatus;
use App\Mcp\Servers\SocialHubServer;
use App\Mcp\Tools\ApproveDraft;
use App\Mcp\Tools\CreateDraft;
use App\Mcp\Tools\GetCandidate;
use App\Mcp\Tools\ListCandidates;
use App\Mcp\Tools\ListDrafts;
use App\Mcp\Tools\ListSources;
use App\Mcp\Tools\MarkManualPosted;
use App\Mcp\Tools\PublishDraft;
use App\Mcp\Tools\PublishStats;
use App\Mcp\Tools\UpdateVariant;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The agent-facing surface. What matters here is not that the tools work, but that a token can only
 * do what it was scoped for: drafting must never be able to publish.
 */
final class SocialHubServerTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('hub.admin_emails', ['ops@example.test']);

        $this->user = User::factory()->create(['email' => 'ops@example.test']);
        $this->brand = Brand::factory()->create(['slug' => 'studentski-poslovi', 'name' => 'Studentski poslovi']);
    }

    public function test_list_sources_describes_the_brands_and_their_channels(): void
    {
        Source::factory()->for($this->brand)->create(['name' => 'studentski-poslovi feed']);
        SocialAccount::factory()->for($this->brand)->create(['name' => 'SP stranica']);

        SocialHubServer::actingAs($this->user)
            ->tool(ListSources::class)
            ->assertOk()
            ->assertSee('studentski-poslovi feed')
            ->assertSee('SP stranica');
    }

    public function test_candidates_and_one_candidate_in_full(): void
    {
        $item = $this->item();

        SocialHubServer::actingAs($this->user)
            ->tool(ListCandidates::class, ['brand' => 'studentski-poslovi'])
            ->assertOk()
            ->assertSee('Konobar/ica');

        SocialHubServer::actingAs($this->user)
            ->tool(GetCandidate::class, ['id' => $item->id])
            ->assertOk()
            ->assertSee('SATNICA');
    }

    public function test_a_missing_candidate_is_an_error_not_a_crash(): void
    {
        SocialHubServer::actingAs($this->user)
            ->tool(GetCandidate::class, ['id' => 9999])
            ->assertHasErrors();
    }

    public function test_creating_a_draft_leaves_it_waiting_for_approval(): void
    {
        $item = $this->item();
        SocialAccount::factory()->for($this->brand)->create();

        $this->actingWithAbilities(['mcp', 'mcp:draft']);

        SocialHubServer::actingAs($this->user)
            ->tool(CreateDraft::class, [
                'content_item_id' => $item->id,
                'facebook_caption' => 'Tekst koji je napisao agent.',
            ])
            ->assertOk()
            ->assertSee('Nacrt čeka odobrenje');

        $draft = PostDraft::query()->firstOrFail();
        $this->assertSame(DraftStatus::PendingApproval, $draft->status);
        $this->assertSame('agent', $draft->created_by_type->value);
        $this->assertSame('Tekst koji je napisao agent.', $draft->variants()->firstOrFail()->caption);
    }

    public function test_a_drafting_token_cannot_approve_or_publish(): void
    {
        $draft = $this->draft();

        $this->actingWithAbilities(['mcp', 'mcp:draft']);

        SocialHubServer::actingAs($this->user)
            ->tool(ApproveDraft::class, ['draft_id' => $draft->id])
            ->assertHasErrors();

        SocialHubServer::actingAs($this->user)
            ->tool(PublishDraft::class, ['draft_id' => $draft->id])
            ->assertHasErrors();

        $this->assertSame(DraftStatus::PendingApproval, $draft->refresh()->status);
    }

    public function test_a_read_only_token_cannot_draft(): void
    {
        $item = $this->item();
        SocialAccount::factory()->for($this->brand)->create();

        $this->actingWithAbilities(['mcp']);

        SocialHubServer::actingAs($this->user)
            ->tool(CreateDraft::class, ['content_item_id' => $item->id])
            ->assertHasErrors();

        $this->assertSame(0, PostDraft::query()->count());
    }

    public function test_an_approving_token_may_approve(): void
    {
        $draft = $this->draft();

        $this->actingWithAbilities(['mcp', 'mcp:draft', 'mcp:approve']);

        SocialHubServer::actingAs($this->user)
            ->tool(ApproveDraft::class, ['draft_id' => $draft->id, 'scheduled_at' => now()->addHour()->toIso8601ZuluString()])
            ->assertOk();

        $this->assertSame(DraftStatus::Scheduled, $draft->refresh()->status);
    }

    public function test_updating_a_variant_rewrites_the_caption_and_can_park_a_channel(): void
    {
        $draft = $this->draft();
        $variant = $draft->variants()->firstOrFail();

        $this->actingWithAbilities(['mcp', 'mcp:draft']);

        SocialHubServer::actingAs($this->user)
            ->tool(UpdateVariant::class, ['variant_id' => $variant->id, 'caption' => 'Novi tekst.', 'enabled' => false])
            ->assertOk();

        $variant->refresh();
        $this->assertSame('Novi tekst.', $variant->caption);
        $this->assertSame(VariantStatus::Disabled, $variant->status);
    }

    public function test_a_published_variant_is_no_longer_editable(): void
    {
        $draft = $this->draft();
        $variant = $draft->variants()->firstOrFail();
        $variant->forceFill(['status' => VariantStatus::Published, 'external_post_id' => '1'])->save();

        $this->actingWithAbilities(['mcp', 'mcp:draft']);

        SocialHubServer::actingAs($this->user)
            ->tool(UpdateVariant::class, ['variant_id' => $variant->id, 'caption' => 'Prekasno.'])
            ->assertHasErrors();

        $this->assertNotSame('Prekasno.', $variant->refresh()->caption);
    }

    public function test_marking_a_group_post_by_hand_requires_the_publish_ability(): void
    {
        $draft = $this->draft(group: true);
        $variant = $draft->variants()->firstOrFail();

        $this->actingWithAbilities(['mcp', 'mcp:draft', 'mcp:approve']);
        SocialHubServer::actingAs($this->user)
            ->tool(MarkManualPosted::class, ['variant_id' => $variant->id])
            ->assertHasErrors();

        $this->actingWithAbilities(['mcp', 'mcp:draft', 'mcp:approve', 'mcp:publish']);
        SocialHubServer::actingAs($this->user)
            ->tool(MarkManualPosted::class, ['variant_id' => $variant->id, 'permalink' => 'https://facebook.com/groups/1/posts/2'])
            ->assertOk();

        $this->assertSame(VariantStatus::ManualDone, $variant->refresh()->status);
    }

    public function test_stats_report_what_went_out_and_what_needs_attention(): void
    {
        $draft = $this->draft();
        $draft->variants()->firstOrFail()->forceFill([
            'status' => VariantStatus::Failed,
            'error_code' => 'token_invalid',
            'error_message' => 'Token je istekao',
        ])->save();

        SocialHubServer::actingAs($this->user)
            ->tool(PublishStats::class, ['days' => 7])
            ->assertOk()
            ->assertSee('token_invalid');
    }

    public function test_drafts_can_be_listed_by_status(): void
    {
        $this->draft();

        SocialHubServer::actingAs($this->user)
            ->tool(ListDrafts::class, ['brand' => 'studentski-poslovi'])
            ->assertOk()
            ->assertSee('Konobar/ica');
    }

    /**
     * @param  list<string>  $abilities
     */
    private function actingWithAbilities(array $abilities): void
    {
        Sanctum::actingAs($this->user, $abilities);
    }

    private function item(): ContentItem
    {
        return ContentItem::factory()
            ->for(Source::factory()->for($this->brand))
            ->for($this->brand)
            ->create([
                'kind' => ContentKind::Job,
                'title' => 'Konobar/ica',
                'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7,00 €/H']],
            ]);
    }

    private function draft(bool $group = false): PostDraft
    {
        $item = $this->item();
        $account = $group
            ? SocialAccount::factory()->for($this->brand)->group()->create()
            : SocialAccount::factory()->for($this->brand)->create();

        return app(\App\Actions\CreateDraft::class)->execute($item, [$account], render: false);
    }
}
