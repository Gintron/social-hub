<?php

declare(strict_types=1);

namespace Tests\Feature\Recipe;

use App\Actions\CreateDraft;
use App\Actions\PrepareVariantMedia;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Jobs\RenderVideoJob;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesRecipeItems;
use Tests\TestCase;

/**
 * A dish becomes one draft with a channel for each network, and every channel carries its own link to the dish's page, the first
 * comment that holds it, and the label that the picture is drawn. The video of a dish says where its link is, so it is made once
 * for the channels that say the same.
 */
final class RecipeDraftTest extends TestCase
{
    use MakesRecipeItems;
    use RefreshDatabase;

    private const PAGE = 'https://uselisto.com/recept/pileca-prsa-s-mahunama-u-vrhnju-c7bd988657';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('elevenlabs.api_key', null);
    }

    public function test_the_page_of_a_dish_is_tracked_on_the_channels_whose_link_can_be_tapped(): void
    {
        $facebook = $this->draft()->variants->firstWhere('platform', Platform::FacebookPage);
        $url = self::PAGE.'?utm_source=facebook&utm_medium=social&utm_campaign=recept-2026-W46';

        $this->assertSame($url, $facebook->link_url);
        $this->assertEquals(['url' => $url, 'source' => 'facebook', 'medium' => 'social', 'campaign' => 'recept-2026-W46'], $facebook->setting('tracking'));
        $this->assertSame("👉 Recept i popis za kupnju: {$url}", $facebook->setting('first_comment'));
        $this->assertSame('comment', $facebook->setting('link_in'));
    }

    public function test_instagram_and_tiktok_say_the_address_of_the_channel_because_that_is_what_people_type(): void
    {
        $draft = $this->draft();

        foreach ([Platform::InstagramBusiness->value => 'instagram', Platform::TikTok->value => 'tiktok'] as $platform => $path) {
            $variant = $draft->variants->firstWhere('platform', Platform::from($platform));

            $this->assertSame("👉 Recept i popis za kupnju: uselisto.com/{$path}", $variant->setting('first_comment'), $platform);
            $this->assertSame("https://uselisto.com/{$path}", $variant->link_url, $platform);
            $this->assertNull($variant->setting('tracking'), 'a comment that does not carry the tracking leaves nothing stored as tracked');
        }
    }

    public function test_a_brand_for_another_market_says_the_address_of_its_market(): void
    {
        $brand = $this->recipeBrand('at', ['voice' => ['cta' => 'Hol dir Listo', 'link_typed_paths' => ['ig_business' => '/at/instagram/']]]);
        $item = $this->recipeItem($brand, market: 'at');
        $draft = app(CreateDraft::class)->execute($item, $this->recipeAccounts($brand, [Platform::InstagramBusiness, Platform::TikTok]), render: false);

        // Instagram: the market's address (it opens the market's page and carries the market's campaign); the slashes around it are not part of it.
        $instagram = $draft->variants->firstWhere('platform', Platform::InstagramBusiness);
        $this->assertSame('👉 Rezept und Einkaufsliste: uselisto.com/at/instagram', $instagram->setting('first_comment'));
        $this->assertSame('https://uselisto.com/at/instagram', $instagram->link_url);

        // A channel the brand has no address of its own for keeps the config's.
        $this->assertSame('👉 Rezept und Einkaufsliste: uselisto.com/tiktok', $draft->variants->firstWhere('platform', Platform::TikTok)->setting('first_comment'));
    }

    public function test_a_youtube_short_has_its_link_in_the_description_and_no_comment_to_leave(): void
    {
        $youtube = $this->draft([Platform::YouTube])->variants->first();

        $this->assertSame('description', $youtube->setting('link_in'));
        $this->assertNull($youtube->setting('first_comment'));
        $this->assertStringContainsString('utm_source=youtube', (string) $youtube->link_url);
        $this->assertStringContainsString('Poveznica je u opisu.', $youtube->caption);
        $this->assertStringContainsString((string) $youtube->link_url, $youtube->caption);
    }

    public function test_a_group_post_keeps_the_plain_page_in_its_text(): void
    {
        $variant = $this->draft([Platform::FacebookGroup])->variants->first();

        $this->assertStringContainsString(self::PAGE, $variant->caption);
        $this->assertNull($variant->setting('first_comment'));
        $this->assertNull($variant->setting('tracking'));
    }

    public function test_the_channel_remembers_which_first_second_the_dish_was_told_with(): void
    {
        $draft = $this->draft();
        $hooks = $draft->variants->map(fn (PostVariant $variant): mixed => $variant->setting('hook'))->unique()->all();

        $this->assertCount(1, $hooks, 'every channel of a dish tells it the same way');
        $this->assertContains($hooks[0], ['question', 'figure']);
    }

    public function test_the_text_of_each_channel_says_the_numbers_where_the_link_is_and_that_the_picture_is_drawn(): void
    {
        foreach ($this->draft()->variants as $variant) {
            $this->assertStringContainsString('4 od 7 sastojaka je na akciji u trgovini Ribola, zajedno 8,96 €', $variant->caption, $variant->platform->value);
            $this->assertStringContainsString('Poveznica je u komentaru.', $variant->caption, $variant->platform->value);
            $this->assertStringContainsString('Slika jela je AI ilustracija.', $variant->caption, $variant->platform->value);
            $this->assertStringNotContainsString('http', $variant->caption, $variant->platform->value);
        }
    }

    public function test_the_defaults_are_a_video_where_the_network_only_takes_video_and_an_image_elsewhere(): void
    {
        $formats = $this->draft([Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok, Platform::YouTube])->variants
            ->mapWithKeys(fn (PostVariant $variant): array => [$variant->platform->value => $variant->format()])->all();

        $this->assertSame(ContentFormat::Image, $formats['fb_page']);
        $this->assertSame(ContentFormat::Image, $formats['ig_business']);
        $this->assertSame(ContentFormat::Video, $formats['tiktok']);
        $this->assertSame(ContentFormat::Video, $formats['youtube']);
    }

    public function test_the_picture_is_labelled_as_drawn_in_every_format_and_a_channel_can_still_say_otherwise(): void
    {
        $draft = $this->draft([Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok]);

        foreach ($draft->variants as $variant) {
            $this->assertTrue($variant->aiGenerated(), $variant->platform->value.' '.$variant->format()->value);
        }

        $variant = $draft->variants->first();
        $variant->putSettings(['ai_generated' => false]);
        $this->assertFalse($variant->fresh()->aiGenerated());

        // A source that says the picture is not drawn is believed: the label is the picture's, not the kind's.
        $photographed = app(CreateDraft::class)->execute($this->recipeItem(feedItem: [...$this->recipeFeedItem(1), 'id' => 'recipe:b', 'raw' => ['recipe' => [...$this->recipeFeedItem(1)['raw']['recipe'], 'ai_image' => false]]], brand: $draft->brand), $this->recipeAccounts($draft->brand, [Platform::FacebookGroup]), render: false);
        $this->assertFalse($photographed->variants->first()->aiGenerated());
    }

    public function test_the_video_is_rendered_once_for_the_channels_that_say_the_same_about_the_link(): void
    {
        $this->draft([Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok], render: true, channelOptions: [
            'fb_page' => ['format' => 'video'],
            'ig_business' => ['format' => 'video'],
        ]);

        Queue::assertPushed(RenderVideoJob::class, 1);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => count($job->variantIds) === 3 && $job->where === 'comment' && ! $job->voiceover);
    }

    public function test_a_youtube_short_gets_a_video_of_its_own_that_says_its_link_is_in_the_description(): void
    {
        $draft = $this->draft([Platform::TikTok, Platform::YouTube], render: true);
        $youtube = $draft->variants->firstWhere('platform', Platform::YouTube);

        Queue::assertPushed(RenderVideoJob::class, 2);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$youtube->id] && $job->where === 'description');
    }

    public function test_a_video_that_points_to_the_comment_is_not_reused_by_a_channel_that_points_to_the_description(): void
    {
        $draft = $this->draft([Platform::TikTok, Platform::YouTube]);
        $video = MediaAsset::factory()->for($draft->brand)->create([
            'post_draft_id' => $draft->id,
            'template_key' => 'video/slideshow',
            'format' => 'mp4',
            'params' => ['link_in' => 'comment'],
        ]);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());

        $youtube = $draft->variants->firstWhere('platform', Platform::YouTube);

        $this->assertCount(1, $video->variants()->get(), 'only the channel that says "u komentaru" took it');
        Queue::assertPushed(RenderVideoJob::class, 1);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$youtube->id] && $job->where === 'description');
    }

    public function test_a_dish_changed_at_the_source_is_not_posted_as_it_was_drafted(): void
    {
        $draft = $this->draft();
        $item = $draft->contentItems->first();

        $this->assertEmpty($draft->staleItems());

        // The sum moved: a post that says 8,96 € over a page that now says another figure is a post about something else.
        $raw = $item->raw;
        $raw['recipe']['total_cents'] = 1100;
        $item->forceFill(['raw' => $raw])->save();

        $this->assertCount(1, $draft->fresh()->staleItems());
    }

    public function test_a_german_dish_is_drafted_in_german_for_the_german_brand(): void
    {
        $brand = $this->recipeBrand('at');
        $item = $this->recipeItem($brand, market: 'at');
        $draft = app(CreateDraft::class)->execute($item, $this->recipeAccounts($brand, [Platform::FacebookPage]), render: false);
        $facebook = $draft->variants->first();

        $this->assertStringContainsString('Den Link findest du im Kommentar.', $facebook->caption);
        $this->assertStringContainsString('https://uselisto.com/at/rezept/hendlfilet-mit-spinat-und-erdaepfeln-1f5dcd2fd6?utm_source=facebook', (string) $facebook->link_url);
        $this->assertStringStartsWith('👉 Rezept und Einkaufsliste: https://uselisto.com/at/rezept/', (string) $facebook->setting('first_comment'));
    }

    /**
     * @param  list<Platform>  $platforms
     */
    private function draft(array $platforms = [Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok], bool $render = false, array $channelOptions = []): PostDraft
    {
        $item = $this->recipeItem();

        return app(CreateDraft::class)->execute($item, $this->recipeAccounts($item->brand, $platforms), render: $render, channelOptions: $channelOptions);
    }
}
