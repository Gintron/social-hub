<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Job;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SocialFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/social-feed')->assertUnauthorized();
    }

    public function test_requires_social_read_ability(): void
    {
        Sanctum::actingAs($this->adminUser(), ['other:ability']);

        $this->getJson('/api/social-feed')->assertForbidden();
    }

    public function test_returns_only_active_paid_listings(): void
    {
        $this->actAsHub();

        $basic = Job::factory()->create(['offer_id' => config('parameters.basic_job')]);
        $start = Job::factory()->create(['offer_id' => config('parameters.start_job')]);
        $plus = Job::factory()->create(['offer_id' => config('parameters.plus_job')]);
        $premium = Job::factory()->create(['offer_id' => config('parameters.premium_job')]);
        $expired = Job::factory()->create(['offer_id' => config('parameters.plus_job'), 'expiring_at' => now()->subDay()]);
        $pending = Job::factory()->create(['offer_id' => config('parameters.plus_job'), 'is_pending_review' => true]);

        $ids = collect($this->getJson('/api/social-feed')->assertOk()->json('items'))->pluck('id');

        $this->assertEqualsCanonicalizing(
            ["job:{$start->id}", "job:{$plus->id}", "job:{$premium->id}"],
            $ids->all(),
        );
        $this->assertNotContains("job:{$basic->id}", $ids);
        $this->assertNotContains("job:{$expired->id}", $ids);
        $this->assertNotContains("job:{$pending->id}", $ids);
    }

    public function test_since_filters_by_updated_at(): void
    {
        $this->actAsHub();

        $old = Job::factory()->create(['offer_id' => config('parameters.plus_job')]);
        $fresh = Job::factory()->create(['offer_id' => config('parameters.plus_job')]);

        DB::table('jobs')->where('id', $old->id)->update(['updated_at' => now()->subDays(3)]);

        $ids = collect($this->getJson('/api/social-feed?since='.urlencode(now()->subDay()->toIso8601ZuluString()))
            ->assertOk()
            ->json('items'))
            ->pluck('id');

        $this->assertSame(["job:{$fresh->id}"], $ids->all());
    }

    public function test_item_follows_social_feed_v1_shape(): void
    {
        $this->actAsHub();

        $category = Category::factory()->create(['name' => 'Ugostiteljstvo', 'emoji' => '🍽️']);
        $location = Location::factory()->create(['name' => 'Split']);

        $job = Job::factory()
            ->hasAttached($location, ['address' => 'Riva 1'])
            ->create([
                'title' => 'Konobar/ica',
                'company_name' => 'Hotel Adriatic',
                'category_id' => $category->id,
                'offer_id' => config('parameters.premium_job'),
                'hour_rate' => 7,
                'max_hour_rate' => 8,
                'is_seasonal' => true,
                'meal' => true,
                'accommodation' => true,
                'image' => 'kartica.webp',
                'description' => '<p>Opis posla:</p><ul><li>Posluživanje</li><li>Rad u smjenama</li></ul>',
            ]);

        $response = $this->getJson('/api/social-feed')->assertOk();

        $response->assertJsonPath('version', '1');
        $response->assertJsonPath('brand', 'studentski-poslovi');
        $response->assertJsonPath('next_cursor', null);

        $item = $response->json('items.0');

        $this->assertSame("job:{$job->id}", $item['id']);
        $this->assertSame('job', $item['kind']);
        $this->assertSame('Konobar/ica', $item['title']);
        $this->assertSame('Hotel Adriatic', $item['subtitle']);
        $this->assertStringContainsString('• Posluživanje', $item['body_text']);
        $this->assertStringNotContainsString('<', $item['body_text']);
        $this->assertContains(['label' => 'LOKACIJA', 'value' => 'SPLIT'], $item['facts']);
        $this->assertContains(['label' => 'SATNICA', 'value' => '7.00 - 8.00 €/H'], $item['facts']);
        $this->assertSame(['Sezonski posao', 'Obrok', 'Smještaj'], $item['badges']);
        $this->assertSame(700, $item['price']['current_cents']);
        $this->assertSame('EUR', $item['price']['currency']);
        $this->assertSame($job->publicUrl(), $item['url']);
        $this->assertSame($job->publicUrl(), $item['cta']['url']);
        $this->assertSame('primary', $item['images'][0]['role']);
        $this->assertMatchesRegularExpression('#^https?://.+/kartica\.webp$#', $item['images'][0]['url']);
        $this->assertSame((int) config('parameters.premium_job'), $item['priority']);
        $this->assertContains('split', $item['tags']);
        $this->assertContains('sezona', $item['tags']);
        $this->assertNotNull($item['published_at']);
        $this->assertNotNull($item['expires_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['updated_at']);
        $this->assertSame('premium', $item['raw']['tier']);
    }

    public function test_paginates_with_cursor(): void
    {
        $this->actAsHub();

        Job::factory()->count(3)->create(['offer_id' => config('parameters.start_job')]);

        $first = $this->getJson('/api/social-feed?limit=2')->assertOk();
        $this->assertCount(2, $first->json('items'));
        $this->assertNotNull($first->json('next_cursor'));

        $second = $this->getJson('/api/social-feed?limit=2&cursor='.urlencode((string) $first->json('next_cursor')))->assertOk();
        $this->assertCount(1, $second->json('items'));
        $this->assertNull($second->json('next_cursor'));

        $this->assertEmpty(array_intersect(
            collect($first->json('items'))->pluck('id')->all(),
            collect($second->json('items'))->pluck('id')->all(),
        ));
    }

    public function test_limit_is_capped(): void
    {
        $this->actAsHub();

        $this->getJson('/api/social-feed?limit=500')->assertUnprocessable();
    }

    private function actAsHub(): User
    {
        $user = $this->adminUser();
        Sanctum::actingAs($user, ['social:read']);

        return $user;
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        Admin::create(['user_id' => $user->id]);

        return $user;
    }
}
