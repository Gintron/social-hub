<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Actions\CreateDraft;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\FormatCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * A quote's video with the real renderers (Chromium, ffmpeg): the card stays up for as long as the text
 * takes to read, and the brand's end card keeps its own hold.
 */
#[Group('render')]
final class QuoteVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $chrome = (string) config('hub.render.chrome_path');

        if ($chrome !== '' && ! is_file($chrome)) {
            $this->markTestSkipped("No Chromium at {$chrome}");
        }

        Storage::fake('public');
        Storage::fake('local');
        config()->set('hub.media_disk', 'public');
        Http::fake(['*' => Http::response('', 404)]);
    }

    public function test_the_card_is_held_for_as_long_as_the_text_takes_to_read(): void
    {
        [$draft, $variant] = $this->draft('Jer Bog je tako ljubio svijet da je dao svoga jedinorođenog Sina da nijedan koji u njega vjeruje ne propadne, nego da ima život vječni.');

        RenderVideoJob::dispatchSync($draft->id, [$variant->id]);

        $asset = $variant->refresh()->media()->firstOrFail();

        // 25 words: a second to arrive and about eight to read, then the 3.5 s end card, less the one fade.
        $this->assertEqualsWithDelta(12.5, (float) $asset->durationSeconds(), 0.15);
        $this->assertCount(2, $asset->params['slides'], 'the card and the end card');
        $this->assertEqualsWithDelta(9.33, $asset->params['slide_seconds'][0], 0.01);
        $this->assertSame(3.5, $asset->params['slide_seconds'][1]);
        $this->assertNull(app(FormatCheck::class)->problem($variant), 'it is ready to publish');
    }

    public function test_a_short_saying_is_not_held_longer_than_it_needs_but_never_less_than_four_seconds(): void
    {
        [$draft, $variant] = $this->draft('Isus zaplaka.');

        RenderVideoJob::dispatchSync($draft->id, [$variant->id]);

        $this->assertEqualsWithDelta(7.15, (float) $variant->refresh()->media()->firstOrFail()->durationSeconds(), 0.15);
    }

    /**
     * @return array{0: PostDraft, 1: PostVariant}
     */
    private function draft(string $title): array
    {
        $brand = Brand::factory()->create([
            'slug' => 'rijec',
            'name' => 'Riječ',
            'site_url' => 'https://rijec.test',
            'voice' => ['cta' => 'Prati nas', 'activation' => 'Jedan stih svaki dan.'],
        ]);

        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Quote,
            'title' => $title,
            'subtitle' => 'Iv 3,16',
            'facts' => [],
            'badges' => [],
            'price' => null,
            'images' => [],
            'expires_at' => '2026-12-31T23:59:59Z',
        ]);

        $draft = app(CreateDraft::class)->execute(
            $item,
            [SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok])],
            status: DraftStatus::PendingApproval,
            render: false,
        );

        /** @var PostVariant $variant */
        $variant = $draft->variants->first();

        return [$draft, $variant];
    }
}
