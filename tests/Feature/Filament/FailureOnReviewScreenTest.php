<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\CreateDraft;
use App\Enums\VariantStatus;
use App\Filament\Resources\PostDrafts\Pages\EditPostDraft;
use App\Filament\Resources\PostDrafts\Pages\ListPostDrafts;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A post that did not go out says why, and what to do about it, where the person is looking.
 */
final class FailureOnReviewScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_a_skipped_channel_says_what_happened_and_what_to_do(): void
    {
        $draft = $this->draftWithVariant(VariantStatus::Skipped, 'dead_link', "Poveznica stavke 'Konobar/ica' više ne odgovara; objava preskočena.");

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertOk()
            ->assertSee('Objava je preskočena')
            ->assertSee("Poveznica stavke 'Konobar/ica' više ne odgovara")
            ->assertSee('Što učiniti')
            ->assertSee('Stranica stavke više ne postoji');
    }

    public function test_a_failed_channel_is_a_failure_not_a_skip(): void
    {
        $draft = $this->draftWithVariant(VariantStatus::Failed, 'token_invalid', 'Račun nije upotrebljiv.');

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertSee('Objava nije izašla')
            ->assertSee('Poveži račun ponovno');
    }

    public function test_a_platform_error_nobody_has_advice_for_is_shown_as_it_came_without_a_guess(): void
    {
        $draft = $this->draftWithVariant(VariantStatus::Failed, 'ig_no_media_id', 'Instagram nije vratio oznaku medija.');

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertSee('Instagram nije vratio oznaku medija.')
            ->assertDontSee('Što učiniti');
    }

    public function test_a_channel_that_is_fine_shows_no_failure(): void
    {
        $draft = $this->draftWithVariant(VariantStatus::Pending, null, null);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertDontSee('Objava nije izašla')
            ->assertDontSee('Objava je preskočena');
    }

    public function test_the_list_gives_the_reason_next_to_the_status(): void
    {
        $draft = $this->draftWithVariant(VariantStatus::Skipped, 'dead_link', "Poveznica stavke 'Konobar/ica' više ne odgovara; objava preskočena.");
        $draft->refreshStatusFromVariants();

        Livewire::test(ListPostDrafts::class)
            ->set('activeTab', 'failed')
            ->assertCanSeeTableRecords([$draft])
            ->assertSee('više ne odgovara');
    }

    private function draftWithVariant(VariantStatus $status, ?string $code, ?string $message): PostDraft
    {
        $brand = Brand::factory()->create();
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);

        $draft->variants()->update(['status' => $status->value, 'error_code' => $code, 'error_message' => $message]);

        return $draft->refresh();
    }
}
