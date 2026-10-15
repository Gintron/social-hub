<?php

declare(strict_types=1);

namespace Tests\Feature\YouTube;

use App\Enums\VariantStatus;
use App\Jobs\PublishVariantJob;
use App\Models\PublishLog;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\Exceptions\TransientPublishException;
use App\Publishing\FormatCheck;
use App\Publishing\LinkPreflight;
use App\Publishing\PublisherRegistry;
use App\Publishing\YouTube\YouTubePublisher;
use App\Support\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesYouTubeVariants;
use Tests\TestCase;

final class YouTubePublisherTest extends TestCase
{
    use MakesYouTubeVariants;
    use RefreshDatabase;

    private const SESSION = 'https://upload.example.test/session?upload_id=abc123';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('youtube.client_id', 'client-id');
        config()->set('youtube.client_secret', 'client-secret');
        config()->set('youtube.privacy', 'public');
        config()->set('youtube.category_id', '22');

        Storage::fake('public');
    }

    public function test_it_uploads_the_short_with_its_title_description_and_privacy_and_returns_the_video_id(): void
    {
        Http::fake($this->googleFakes());

        $variant = $this->youtubeVariant();
        $result = app(YouTubePublisher::class)->publish($variant);

        $this->assertSame('vid-123', $result->externalId);
        $this->assertSame('https://www.youtube.com/shorts/vid-123', $result->permalink);
        $this->assertFalse($result->handedToCreator);

        // The metadata of the resumable session: first line is the title, the rest the description.
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'https://www.googleapis.com/upload/youtube/v3/videos?')
                && $data['snippet']['title'] === '🍽️ Konobar/ica · 7,00 €/H'
                && str_starts_with($data['snippet']['description'], 'Hotel Adriatic')
                && $data['snippet']['categoryId'] === '22'
                && $data['status']['privacyStatus'] === 'public'
                && $data['status']['selfDeclaredMadeForKids'] === false
                && $request->header('X-Upload-Content-Type')[0] === 'video/mp4'
                && $request->header('X-Upload-Content-Length')[0] === '4096';
        });

        // The file itself goes to the session URL Google returned.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === self::SESSION
            && $request->header('Content-Type')[0] === 'video/mp4');
    }

    public function test_an_expired_access_token_is_traded_for_a_fresh_one_and_kept(): void
    {
        Http::fake($this->googleFakes());

        $variant = $this->youtubeVariant(['token_valid' => false]);
        app(YouTubePublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && ($request->data()['grant_type'] ?? null) === 'refresh_token'
            && ($request->data()['refresh_token'] ?? null) === 'refresh-1');

        $account = $variant->account->fresh();
        $this->assertSame('fresh-token', $account->access_token);
        $this->assertSame('refresh-1', $account->refresh_token);
        $this->assertTrue($account->token_expires_at->isFuture());
    }

    public function test_a_token_still_in_date_is_used_without_asking_google_again(): void
    {
        Http::fake($this->googleFakes());

        app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => true]));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com'));
    }

    public function test_a_private_upload_from_an_unaudited_project_is_recorded_not_failed(): void
    {
        Http::fake($this->googleFakes(privacy: 'private'));
        Log::spy();

        $variant = $this->youtubeVariant(['token_valid' => true]);
        $result = app(YouTubePublisher::class)->publish($variant);

        // The post still counts as published; the hub keeps what Google kept and says what was asked for.
        $this->assertSame('vid-123', $result->externalId);
        $this->assertSame('public', $result->raw['requested_privacy']);
        $this->assertSame('private', $result->raw['privacy_status']);

        Log::shouldHaveReceived('warning')->with('hub.youtube.privacy_adjusted', [
            'variant' => $variant->id,
            'requested' => 'public',
            'applied' => 'private',
        ])->once();
    }

    public function test_the_variant_can_ask_for_unlisted(): void
    {
        Http::fake($this->googleFakes(privacy: 'unlisted'));

        app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => true, 'settings' => ['privacy' => 'unlisted']]));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['status']['privacyStatus'] ?? null) === 'unlisted');
    }

    public function test_a_visibility_stored_as_null_uploads_with_the_hubs_default(): void
    {
        Http::fake($this->googleFakes());
        config()->set('youtube.privacy', 'unlisted');

        // What an auto-publish rule with the visibility left unfilled used to hand every Short it made.
        $result = app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => true, 'settings' => ['privacy' => null]]));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['status']['privacyStatus'] ?? null) === 'unlisted');
        $this->assertSame('unlisted', $result->raw['requested_privacy']);
    }

    public function test_each_call_is_logged_on_the_variant_without_any_token_in_it(): void
    {
        Http::fake($this->googleFakes());

        $variant = $this->youtubeVariant(['token_valid' => false]);
        app(YouTubePublisher::class)->publish($variant);

        $logs = PublishLog::query()->where('post_variant_id', $variant->id)->orderBy('id')->get();

        $this->assertSame(['youtube.upload_start', 'youtube.upload'], $logs->pluck('event')->all());
        $this->assertSame(201, $logs->last()->http_status);

        $everything = json_encode($logs->map->toArray()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('fresh-token', $everything);
        $this->assertStringNotContainsString('stale-token', $everything);
        $this->assertStringNotContainsString('upload_id', $everything);
    }

    public function test_an_exhausted_quota_waits_for_the_midnight_reset_in_pacific_time(): void
    {
        Http::fake([
            ...$this->tokenFake(),
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
                'error' => ['code' => 403, 'message' => 'The request cannot be completed because you have exceeded your quota.', 'errors' => [['reason' => 'quotaExceeded']]],
            ], 403),
        ]);

        try {
            app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => true]));
            $this->fail('A quota error must not publish.');
        } catch (RateLimitedException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertTrue($e->retryable);
            $this->assertGreaterThanOrEqual(60, $e->retryAfterSeconds);
            // The reset is at most a day away.
            $this->assertLessThanOrEqual(86_400 + 60, $e->retryAfterSeconds);
        }
    }

    public function test_a_revoked_grant_asks_for_a_new_connection(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
        ]);

        try {
            app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => false]));
            $this->fail('A revoked grant must not publish.');
        } catch (TokenInvalidException $e) {
            $this->assertSame('token_invalid', $e->errorCode);
            $this->assertStringContainsString('poveži YouTube kanal ponovno', $e->getMessage());
        }
    }

    public function test_a_grant_without_the_upload_permission_asks_for_a_new_connection(): void
    {
        Http::fake([
            ...$this->tokenFake(),
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
                'error' => ['code' => 403, 'message' => 'Insufficient Permission', 'errors' => [['reason' => 'insufficientPermissions']]],
            ], 403),
        ]);

        $this->expectException(TokenInvalidException::class);

        app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => true]));
    }

    public function test_a_server_error_is_retried_with_a_new_session(): void
    {
        Http::fake([
            ...$this->tokenFake(),
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 503),
        ]);

        try {
            app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => true]));
            $this->fail('A 503 must not publish.');
        } catch (TransientPublishException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_a_video_google_took_without_an_id_is_not_uploaded_again(): void
    {
        Http::fake([
            ...$this->tokenFake(),
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => self::SESSION]),
            'https://upload.example.test/*' => Http::response(['status' => ['privacyStatus' => 'public']], 201),
        ]);

        try {
            app(YouTubePublisher::class)->publish($this->youtubeVariant(['token_valid' => true]));
            $this->fail('A reply without an id must not publish.');
        } catch (PermanentPublishException $e) {
            // A retry would upload a second copy, so the person checks the channel first.
            $this->assertSame('youtube_no_video_id', $e->errorCode);
            $this->assertFalse($e->retryable);
        }
    }

    public function test_the_publish_job_puts_the_short_live_and_a_retry_does_not_upload_it_again(): void
    {
        Http::fake($this->googleFakes());

        $variant = $this->youtubeVariant(['token_valid' => false]);
        $job = fn (): PublishVariantJob => new PublishVariantJob($variant->id);

        $job()->handle(app(PublisherRegistry::class), app(AdminNotifier::class), app(LinkPreflight::class), app(FormatCheck::class), app(\App\Publishing\RestrictedGoodsCheck::class));

        $variant->refresh();
        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('vid-123', $variant->external_post_id);
        $this->assertSame('https://www.youtube.com/shorts/vid-123', $variant->permalink);

        // A retry finds the id already there and must not open a second upload.
        $job()->handle(app(PublisherRegistry::class), app(AdminNotifier::class), app(LinkPreflight::class), app(FormatCheck::class), app(\App\Publishing\RestrictedGoodsCheck::class));

        $uploads = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com/upload/youtube/v3/videos'));
        $this->assertCount(1, $uploads);
    }

    public function test_an_account_without_a_refresh_token_cannot_publish(): void
    {
        $variant = $this->youtubeVariant(['token_valid' => true]);
        $variant->account->forceFill(['refresh_token' => null])->save();

        $this->expectException(PermanentPublishException::class);

        app(YouTubePublisher::class)->publish($variant->fresh(['account', 'media']));
    }

    /**
     * What Google answers for a whole upload: a refreshed token, a session URL, and the created video.
     *
     * @return array<string, mixed>
     */
    private function googleFakes(string $privacy = 'public'): array
    {
        return [
            ...$this->tokenFake(),
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => self::SESSION]),
            'https://upload.example.test/*' => Http::response([
                'id' => 'vid-123',
                'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => $privacy],
            ], 201),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenFake(): array
    {
        return [
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-token',
                'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload',
                'token_type' => 'Bearer',
            ], 200),
        ];
    }
}
