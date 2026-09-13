<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\ApproveDraft;
use App\Actions\CreateDraft;
use App\Actions\ScheduleDraft;
use App\Filament\Pages\PublishingCalendar;
use App\Filament\Resources\ContentItems\Pages\ListContentItems;
use App\Filament\Resources\ContentItems\Pages\ViewContentItem;
use App\Filament\Resources\PostDrafts\Pages\EditPostDraft;
use App\Filament\Resources\PostDrafts\Pages\ListPostDrafts;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->admin = User::factory()->create(['email' => 'ops@example.test']);
    }

    public function test_only_allowlisted_emails_can_access_the_panel(): void
    {
        $this->assertTrue($this->admin->canAccessPanel(filament()->getDefaultPanel()));
        $this->assertFalse(User::factory()->create(['email' => 'someone@example.test'])->canAccessPanel(filament()->getDefaultPanel()));
    }

    public function test_pages_render_for_an_admin(): void
    {
        $this->actingAs($this->admin);

        $brand = Brand::factory()->create();
        $source = Source::factory()->for($brand)->create();
        $item = ContentItem::factory()->for($source)->for($brand)->create();
        $page = SocialAccount::factory()->for($brand)->create();
        SocialAccount::factory()->for($brand)->group()->create();
        $draft = app(CreateDraft::class)->execute($item, [$page], render: false);

        $this->get('/admin')->assertOk();
        $this->get('/admin/brands')->assertOk();
        Livewire::test(ListSources::class)->assertOk()->assertSee($source->name);
        Livewire::test(EditSource::class, ['record' => $source->getRouteKey()])->assertOk();
        Livewire::test(ListSocialAccounts::class)->assertOk()->assertSee($page->name);
        Livewire::test(ListContentItems::class)->assertOk()->assertSee($item->title);
        Livewire::test(ViewContentItem::class, ['record' => $item->getRouteKey()])->assertOk()->assertSee($item->title);
        Livewire::test(ListPostDrafts::class)->assertOk()->assertSee($draft->title);
        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertOk()
            ->assertSee('Spremi i objavi')
            ->assertSee('Objavi na ovaj kanal')
            ->assertSee('Format');
    }

    public function test_calendar_shows_scheduled_and_published_drafts(): void
    {
        $this->actingAs($this->admin);

        $brand = Brand::factory()->create(['name' => 'Studentski poslovi']);
        $source = Source::factory()->for($brand)->create();
        $item = ContentItem::factory()->for($source)->for($brand)->create(['title' => 'Konobar u Splitu']);
        $account = SocialAccount::factory()->for($brand)->create();

        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
        app(ApproveDraft::class)->execute($draft, $this->admin->id);
        app(ScheduleDraft::class)->execute($draft, now()->addDays(2)->setTime(9, 30)->toImmutable());

        $other = Brand::factory()->create(['name' => 'Radim']);

        Livewire::test(PublishingCalendar::class)
            ->assertOk()
            ->assertSee('Konobar u Splitu')
            ->assertSee('11:30')                       // 09:30 UTC rendered in Europe/Zagreb
            ->set('brandId', $other->id)
            ->assertDontSee('Konobar u Splitu')
            ->set('brandId', $brand->id)
            ->assertSee('Konobar u Splitu');
    }

    public function test_calendar_navigates_between_months(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(PublishingCalendar::class)
            ->assertSet('month', now('Europe/Zagreb')->format('Y-m'))
            ->call('nextMonth')
            ->assertSet('month', now('Europe/Zagreb')->addMonthNoOverflow()->format('Y-m'))
            ->call('previousMonth')
            ->call('previousMonth')
            ->assertSet('month', now('Europe/Zagreb')->subMonthNoOverflow()->format('Y-m'))
            ->call('today')
            ->assertSet('month', now('Europe/Zagreb')->format('Y-m'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/post-drafts')->assertRedirect('/admin/login');
    }
}
