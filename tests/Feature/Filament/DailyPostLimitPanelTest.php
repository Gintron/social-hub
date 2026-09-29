<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DailyPostLimitPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_brand_form_saves_and_clears_the_daily_post_limit(): void
    {
        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->assertOk()
            ->assertSee('Najviše automatskih objava dnevno')
            ->fillForm(['daily_post_limit' => 3])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $brand->refresh()->daily_post_limit);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['daily_post_limit' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($brand->refresh()->daily_post_limit);
    }
}
