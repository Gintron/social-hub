<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\CreateDraft;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Filament\Resources\PostDrafts\Pages\EditPostDraft;
use App\Jobs\PublishVariantJob;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The review screen: a tab per channel, and actions that act on what is on screen.
 */
final class EditPostDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
        Queue::fake();
    }

    public function test_publish_saves_the_caption_on_screen_before_it_goes_out(): void
    {
        $draft = $this->draft();
        $variant = $draft->variants->first();

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertSet("data.channels.v{$variant->id}.caption", $variant->caption)
            ->set("data.channels.v{$variant->id}.caption", 'Novi tekst iz pregleda')
            ->callAction('publish_now');

        // It used to publish whatever was in the database, i.e. the old wording.
        $this->assertSame('Novi tekst iz pregleda', $variant->refresh()->caption);
        Queue::assertPushed(PublishVariantJob::class, fn (PublishVariantJob $job): bool => $job->variantId === $variant->id);
    }

    public function test_changing_one_channels_format_renders_for_that_channel_only(): void
    {
        $draft = $this->draft(withTikTok: true);
        $page = $draft->variants->firstWhere('platform', Platform::FacebookPage);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertSet("data.channels.v{$page->id}.format", 'image')
            ->set("data.channels.v{$page->id}.format", 'video');

        $this->assertSame(ContentFormat::Video, $page->refresh()->format());
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$page->id]);
    }

    public function test_every_channel_is_a_tab_with_its_format_and_state(): void
    {
        $draft = $this->draft(withTikTok: true);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertOk()
            ->assertSee('Facebook')
            ->assertSee('TikTok')
            ->assertSee('Slika · nije spremno')
            ->assertSee('Video · nije spremno');
    }

    private function draft(bool $withTikTok = false): PostDraft
    {
        $brand = Brand::factory()->create();
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);
        $accounts = [SocialAccount::factory()->for($brand)->create()];

        if ($withTikTok) {
            $accounts[] = SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]);
        }

        return app(CreateDraft::class)->execute($item, $accounts, render: false);
    }
}
