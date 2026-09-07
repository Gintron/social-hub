<?php

declare(strict_types=1);

namespace Tests\Feature\Meta;

use App\Enums\AccountStatus;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Notifications\AccountNeedsReconnect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_secret', self::SECRET);
        config()->set('hub.admin_emails', ['ops@example.test']);
    }

    public function test_deauthorize_marks_the_connected_accounts_for_reconnect(): void
    {
        Notification::fake();

        $mine = $this->account('fb-user-1');
        $other = $this->account('fb-user-2');

        $this->postJson(route('meta.deauthorize'), ['signed_request' => $this->signedRequest(['user_id' => 'fb-user-1'])])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'accounts' => 1]);

        $this->assertSame(AccountStatus::NeedsReconnect, $mine->refresh()->status);
        $this->assertSame(AccountStatus::Active, $other->refresh()->status);

        Notification::assertSentOnDemand(AccountNeedsReconnect::class);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $account = $this->account('fb-user-1');

        $forged = $this->signedRequest(['user_id' => 'fb-user-1'], 'wrong-secret');

        $this->postJson(route('meta.deauthorize'), ['signed_request' => $forged])->assertStatus(400);
        $this->postJson(route('meta.deauthorize'), ['signed_request' => 'garbage'])->assertStatus(400);
        $this->postJson(route('meta.deauthorize'), [])->assertStatus(400);

        $this->assertSame(AccountStatus::Active, $account->refresh()->status);
    }

    public function test_data_deletion_clears_tokens_and_returns_a_confirmation(): void
    {
        $account = $this->account('fb-user-1');

        $response = $this->postJson(route('meta.data-deletion'), ['signed_request' => $this->signedRequest(['user_id' => 'fb-user-1'])])
            ->assertOk()
            ->assertJsonStructure(['url', 'confirmation_code']);

        $code = $response->json('confirmation_code');
        $this->assertSame(route('meta.data-deletion.status', ['code' => $code]), $response->json('url'));

        $account->refresh();
        $this->assertNull($account->access_token);
        $this->assertSame(AccountStatus::Disabled, $account->status);
        $this->assertSame($code, $account->meta['deletion_code']);

        $this->get($response->json('url'))
            ->assertOk()
            ->assertSee($code)
            ->assertSee('Uklonjeni su pristupni tokeni');
    }

    public function test_the_status_page_is_honest_about_an_unknown_code(): void
    {
        $this->get(route('meta.data-deletion.status', ['code' => 'NOPE']))
            ->assertOk()
            ->assertSee('Nema zapisa uz ovaj kod');
    }

    private function account(string $connectedUserId): SocialAccount
    {
        return SocialAccount::factory()->for(Brand::factory())->create([
            'meta' => ['page_id' => 'p1', 'connected_user_id' => $connectedUserId],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedRequest(array $payload, string $secret = self::SECRET): string
    {
        $encodedPayload = $this->base64Url(json_encode($payload + ['algorithm' => 'HMAC-SHA256'], JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, $secret, true);

        return $this->base64Url($signature).'.'.$encodedPayload;
    }

    private function base64Url(string $value): string
    {
        return mb_rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
