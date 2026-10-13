<?php

declare(strict_types=1);

namespace Tests\Feature\YouTube;

use App\Actions\CreateDraft;
use App\Enums\Platform;
use App\Filament\Resources\PostDrafts\Pages\EditPostDraft;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The review screen, the connect button and a source rule render for a YouTube channel. Each of these
 * matches on Platform, so a missing arm shows up here rather than on the day someone opens the page.
 */
final class YouTubePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        config()->set('youtube.client_id', 'client-id');
        config()->set('youtube.client_secret', 'client-secret');

        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_a_draft_with_a_youtube_channel_renders_its_short_tab(): void
    {
        $brand = Brand::factory()->create();
        $source = Source::factory()->for($brand)->create();
        $item = ContentItem::factory()->for($source)->for($brand)->create();
        $channel = SocialAccount::factory()->for($brand)->create(['platform' => Platform::YouTube, 'name' => 'Studentski poslovi']);

        $draft = app(CreateDraft::class)->execute($item, [$channel], render: false);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertOk()
            ->assertSee('YouTube')
            ->assertSee('Short')
            ->assertSee('Vidljivost na YouTubeu')
            ->assertSee('naslov ');
    }

    public function test_the_connect_button_for_youtube_is_offered_once_the_client_is_configured(): void
    {
        Livewire::test(ListSocialAccounts::class)->assertOk()->assertSee('Poveži YouTube');
    }

    public function test_a_source_rule_for_youtube_renders_with_its_visibility(): void
    {
        $brand = Brand::factory()->create();
        $source = Source::factory()->for($brand)->create();
        $source->autoPublishRules()->create([
            'platform' => Platform::YouTube,
            'enabled' => true,
            'format' => 'video',
            'settings' => ['privacy' => 'unlisted'],
        ]);

        Livewire::test(EditSource::class, ['record' => $source->getRouteKey()])->assertOk();
    }
}
