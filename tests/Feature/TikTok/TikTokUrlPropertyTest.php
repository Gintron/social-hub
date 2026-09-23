<?php

declare(strict_types=1);

namespace Tests\Feature\TikTok;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class TikTokUrlPropertyTest extends TestCase
{
    private const PREFIX = 'https://hub.test/storage/media/';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.media_disk', 'public');
        config()->set('tiktok.business.app_id', 'app-id');
        config()->set('tiktok.business.app_secret', 'app-secret');
        Storage::fake('public', ['url' => 'https://hub.test/storage']);
    }

    public function test_it_writes_the_signature_under_the_media_prefix_and_verifies_it(): void
    {
        Http::fake([
            '*/business/property/list/*' => Http::response(['code' => 0, 'data' => ['url_property_info_list' => []]]),
            '*/business/property/add/' => Http::response(['code' => 0, 'data' => ['url_property_info' => [
                'url' => self::PREFIX, 'property_type' => 2, 'property_status' => 0,
                'signature' => 'sig-123', 'file_name' => 'tiktokAbc.txt',
            ]]]),
            self::PREFIX.'tiktokAbc.txt' => Http::response('sig-123'),
            '*/business/property/verify/' => Http::response(['code' => 0, 'data' => ['url_property_info' => [
                'url' => self::PREFIX, 'property_type' => 2, 'property_status' => 1,
            ]]]),
        ]);

        $this->artisan('hub:tiktok-url-property')->assertSuccessful();

        Storage::disk('public')->assertExists('media/tiktokAbc.txt');
        $this->assertSame('sig-123', Storage::disk('public')->get('media/tiktokAbc.txt'));

        // Verification is per app: credentials in the body, no account token anywhere.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/business/property/add/')
            && $request['app_id'] === 'app-id'
            && $request['secret'] === 'app-secret'
            && $request['url_property_meta'] === ['url' => self::PREFIX, 'property_type' => 2]
            && ! $request->hasHeader('Access-Token'));
    }

    public function test_an_already_verified_prefix_is_left_alone(): void
    {
        Http::fake([
            '*/business/property/list/*' => Http::response(['code' => 0, 'data' => ['url_property_info_list' => [
                ['url' => self::PREFIX, 'property_type' => 2, 'property_status' => 1, 'signature' => 's', 'file_name' => 'f.txt'],
            ]]]),
        ]);

        $this->artisan('hub:tiktok-url-property')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_a_pending_prefix_reuses_its_signature_instead_of_adding_again(): void
    {
        Http::fake([
            '*/business/property/list/*' => Http::response(['code' => 0, 'data' => ['url_property_info_list' => [
                ['url' => self::PREFIX, 'property_type' => 2, 'property_status' => 2, 'signature' => 'sig-old', 'file_name' => 'tiktokOld.txt'],
            ]]]),
            self::PREFIX.'tiktokOld.txt' => Http::response('sig-old'),
            '*/business/property/verify/' => Http::response(['code' => 0, 'data' => ['url_property_info' => ['property_status' => 1]]]),
        ]);

        $this->artisan('hub:tiktok-url-property')->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/business/property/add/'));
    }

    public function test_a_failed_verification_is_reported_as_failure(): void
    {
        Http::fake([
            '*/business/property/list/*' => Http::response(['code' => 0, 'data' => ['url_property_info_list' => []]]),
            '*/business/property/add/' => Http::response(['code' => 0, 'data' => ['url_property_info' => [
                'signature' => 'sig-123', 'file_name' => 'tiktokAbc.txt',
            ]]]),
            self::PREFIX.'tiktokAbc.txt' => Http::response('sig-123'),
            '*/business/property/verify/' => Http::response(['code' => 0, 'data' => ['url_property_info' => ['property_status' => 2]]]),
        ]);

        $this->artisan('hub:tiktok-url-property')->assertFailed();
    }

    public function test_it_does_not_ask_tiktok_when_the_signature_is_not_actually_served(): void
    {
        Http::fake([
            '*/business/property/list/*' => Http::response(['code' => 0, 'data' => ['url_property_info_list' => []]]),
            '*/business/property/add/' => Http::response(['code' => 0, 'data' => ['url_property_info' => [
                'signature' => 'sig-123', 'file_name' => 'tiktokAbc.txt',
            ]]]),
            self::PREFIX.'tiktokAbc.txt' => Http::response('', 302, ['Location' => 'https://hub.test/login']),
        ]);

        $this->artisan('hub:tiktok-url-property')->assertFailed();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/business/property/verify/'));
    }

    public function test_a_local_http_media_url_is_refused_before_calling_tiktok(): void
    {
        Storage::fake('public', ['url' => 'http://localhost:8100/storage']);
        Http::fake();

        $this->artisan('hub:tiktok-url-property')->assertFailed();

        Http::assertNothingSent();
    }
}
