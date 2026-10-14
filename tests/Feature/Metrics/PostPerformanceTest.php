<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Actions\CreateDraft;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Filament\Pages\PostPerformance;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PostPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_metrics_are_unavailable_and_developer_gaps_remain_actionable(): void
    {
        config()->set('hub.admin_emails', ['ops@example.test']);
        config()->set('tiktok.driver', 'developer');
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
        $brand = Brand::factory()->create();
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create();
        $accounts = [
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok, 'meta' => ['api' => 'business']]),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok, 'meta' => ['api' => 'developer']]),
        ];
        $draft = app(CreateDraft::class)->execute($item, $accounts, render: false);

        foreach ($draft->variants as $variant) {
            $variant->update(['status' => VariantStatus::Published, 'external_post_id' => 'published-'.$variant->id, 'published_at' => now()->subDay()]);
        }

        $page = Livewire::test(PostPerformance::class)
            ->assertSee('TikTok API for Business: očitanja nisu dostupna za 1 objava.')
            ->assertSee('Nedostaju očitanja za objavljene kanale')
            ->assertSee('To nije nula pregleda');

        $this->assertSame(1, $page->instance()->unavailableMetricsCount());
        $this->assertSame(1, $page->instance()->unmeasuredCount());
        $this->assertSame(0, $page->instance()->measuredCount());
        $this->assertSame(0, $draft->variants->sum(fn ($variant): int => $variant->metrics()->count()));
    }
}
