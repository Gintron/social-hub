<?php

declare(strict_types=1);

namespace Tests\Feature\TikTok;

use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Publishing\Meta\FacebookPagePublisher;
use App\Publishing\Meta\InstagramPublisher;
use App\Publishing\PublisherRegistry;
use App\Publishing\TikTok\Business\TikTokBusinessPublisher;
use App\Publishing\TikTok\TikTokPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PublisherRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_connected_accounts_keep_the_api_that_issued_their_tokens(): void
    {
        Http::fake();
        $business = SocialAccount::factory()->create(['platform' => Platform::TikTok, 'meta' => ['api' => 'business']]);
        $developer = SocialAccount::factory()->create(['platform' => Platform::TikTok, 'meta' => ['api' => 'developer']]);
        $legacy = SocialAccount::factory()->create(['platform' => Platform::TikTok, 'meta' => null]);
        $registry = app(PublisherRegistry::class);

        foreach (['business', 'developer'] as $driver) {
            config()->set('tiktok.driver', $driver);
            $this->assertInstanceOf(TikTokBusinessPublisher::class, $registry->for(Platform::TikTok, $business->fresh()));
            $this->assertInstanceOf(TikTokPublisher::class, $registry->for(Platform::TikTok, $developer->fresh()));
            $this->assertInstanceOf(TikTokPublisher::class, $registry->for(Platform::TikTok, $legacy->fresh()));
        }

        Http::assertNothingSent();
    }

    public function test_without_an_account_context_the_configured_driver_is_used(): void
    {
        $registry = app(PublisherRegistry::class);
        config()->set('tiktok.driver', 'business');
        $this->assertInstanceOf(TikTokBusinessPublisher::class, $registry->for(Platform::TikTok));
        config()->set('tiktok.driver', 'developer');
        $this->assertInstanceOf(TikTokPublisher::class, $registry->for(Platform::TikTok));
    }

    public function test_account_metadata_does_not_change_other_platforms(): void
    {
        $account = SocialAccount::factory()->make(['meta' => ['api' => 'business']]);
        $registry = app(PublisherRegistry::class);

        $this->assertInstanceOf(FacebookPagePublisher::class, $registry->for(Platform::FacebookPage, $account));
        $this->assertInstanceOf(InstagramPublisher::class, $registry->for(Platform::InstagramBusiness, $account));
    }
}
