<?php

declare(strict_types=1);

namespace Tests\Feature\Recipe;

use App\Actions\CreateDraft;
use App\Actions\PrepareVariantMedia;
use App\Catalog\CatalogVideoPlan;
use App\Enums\Platform;
use App\Jobs\RenderRecipeVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Publishing\FormatCheck;
use App\Recipe\RecipeCopy;
use App\Recipe\RecipeSceneBundle;
use App\Recipe\RecipeVideoPlan;
use App\Rendering\RecipeVideo\RecipeVideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSpeech;
use Tests\Support\MakesRecipeItems;
use Tests\TestCase;

/**
 * The video of a dish as a scene in motion: when things happen (the plan), what the scene is given to print (the bundle), which brand
 * gets it instead of the three slides, and, with Chromium, the finished file.
 */
final class RecipeSceneVideoTest extends TestCase
{
    use MakesRecipeItems;
    use RefreshDatabase;

    private const SCENE = ['recipe_video' => 'scene'];

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        // RemoteImageCache keeps files for a week: a URL a former run fetched would be answered from the disk.
        File::deleteDirectory(storage_path('app/cache/images'));
        config()->set('elevenlabs.api_key', null);
    }

    public function test_the_video_waits_for_its_three_sentences(): void
    {
        $lines = ['Što kuhati ovaj tjedan?', '4 od 6 sastojaka je na akciji u trgovini Konzum.', 'Recept i popis su u aplikaciji Listo. Poveznica je u komentaru.'];
        $plan = RecipeVideoPlan::build($lines, [1.4, 3.9, 4.4], dishes: 3, rows: 4);

        // Three dishes before the screen changes, the dish of the post last and seen for a while.
        $this->assertCount(3, $plan['swaps']);
        $this->assertSame(0.0, $plan['swaps'][0]);
        $this->assertGreaterThanOrEqual($plan['swaps'][2] + 0.7, $plan['hookEnd']);
        $this->assertGreaterThanOrEqual(CatalogVideoPlan::VOICE_LEAD + 1.4, $plan['hookEnd'], 'the hook is said before the screen changes');

        // What the card's sentence names is shown while it is said, once the screen has stopped moving.
        [$one, $two, $three] = $plan['pulses'];
        $this->assertGreaterThan($plan['scrollAt'] + 0.6, $one);
        $this->assertGreaterThanOrEqual(0.5, round($two - $one, 3));
        $this->assertGreaterThanOrEqual(0.5, round($three - $two, 3));
        $this->assertGreaterThanOrEqual($plan['voice'][1]['at'] + 3.9, $plan['tapAt'], 'the tap comes after the sentence of the card');

        // The list comes up on the tap and stays for the first half of the closing; the end card is there for where the link is.
        $this->assertGreaterThan($plan['tapAt'], $plan['listAt']);
        $this->assertCount(4, $plan['rowsAt']);
        $this->assertGreaterThan($plan['rowsAt'][3] + 1.0, $plan['ctaAt']);
        $this->assertLessThan($plan['voice'][2]['at'] + 4.4, $plan['ctaAt']);
        $this->assertGreaterThanOrEqual($plan['voice'][2]['at'] + 4.4, $plan['duration'], 'nothing is cut off');
        $this->assertGreaterThanOrEqual($plan['ctaAt'] + 2.6, $plan['duration']);
        $this->assertLessThan(16.0, $plan['duration']);

        // One "added" sound, on the frame of the tap; what a frame is drawn at is a multiple of 1/30 s.
        $this->assertSame([$plan['tapAt']], $plan['sounds']);

        foreach ([$plan['hookEnd'], $plan['tapAt'], $plan['listAt'], $plan['ctaAt'], $plan['duration']] as $at) {
            $this->assertEqualsWithDelta(round($at * 30), $at * 30, 0.001);
        }
    }

    public function test_without_a_voice_and_without_a_list_it_is_the_same_video_shorter(): void
    {
        $lines = ['Što kuhati ovaj tjedan?', '4 od 6 sastojaka je na akciji u trgovini Konzum.', 'Recept i popis su u aplikaciji Listo. Poveznica je u komentaru.'];
        $plan = RecipeVideoPlan::build($lines, null, dishes: 1, rows: 0);

        $this->assertSame([0.0], $plan['swaps']);
        $this->assertSame([], $plan['voice'], 'no voice, no clips to place');
        $this->assertCount(3, $plan['captions'], 'the sentences are still on the screen');
        $this->assertNull($plan['listAt']);
        $this->assertSame([], $plan['rowsAt']);
        $this->assertEqualsWithDelta($plan['tapAt'] + 0.35, $plan['ctaAt'], 0.04, 'the tap opens the end card');
        $this->assertGreaterThan(8.0, $plan['duration']);
        $this->assertLessThan(16.0, $plan['duration']);
    }

    public function test_the_scene_is_given_the_dish_its_numbers_and_two_more_dishes_and_no_product(): void
    {
        $this->fakePictures();
        $brand = $this->recipeBrand(attributes: ['voice' => ['cta' => 'Preuzmi Listo', 'recipe_hook' => 'question', 'recipe_highlights' => true, ...self::SCENE]]);
        $item = $this->recipeItem($brand, $this->recipeFeedItem(1));
        $this->recipeItem($brand, $this->recipeFeedItem(0));
        $this->recipeItem($brand, $this->recipeFeedItem(4));
        // Neither a dish with alcohol nor one that has run out is shown beside this one.
        $this->recipeItem($brand, $this->recipeFeedItem(2), ['restricted' => ['alcohol']]);
        $this->recipeItem($brand, $this->recipeFeedItem(3), ['expires_at' => now()->subHour()]);

        $bundles = app(RecipeSceneBundle::class);
        $shape = $bundles->shape($item, $brand);
        $this->assertSame(['dishes' => 3, 'rows' => 4], $shape);

        $dir = storage_path('app/tmp/recipe-bundle-test');
        File::deleteDirectory($dir);

        try {
            $plan = RecipeVideoPlan::build(array_values(RecipeCopy::voiceLines($item, $brand)), null, $shape['dishes'], $shape['rows']);
            $data = $bundles->make($brand, $item, $plan, 'comment', $dir);

            foreach (['index.html', 'scene.js', 'scene.css', 'data.js', 'fonts/Fraunces_700Bold.ttf', 'assets/brand.png', 'assets/dish-0.png'] as $file) {
                $this->assertFileExists("{$dir}/{$file}");
            }

            $script = (string) file_get_contents("{$dir}/data.js");
        } finally {
            File::deleteDirectory($dir);
        }

        // The hook: the question, two other dishes and then this one, each with how many of its ingredients are on sale. Never a sum.
        $this->assertSame('Što kuhati ovaj tjedan?', $data['hook']['headline']);
        $this->assertCount(3, $data['hook']['dishes']);
        $this->assertSame('Pečeni bataci s brokulom i paprikom', $data['hook']['dishes'][2]['title']);
        $this->assertSame(['4 sastojka', 'na akciji'], [$data['hook']['dishes'][2]['big'], $data['hook']['dishes'][2]['small']]);
        $titles = array_column($data['hook']['dishes'], 'title');
        $this->assertNotContains('Svinjetina s kiselom repom', $titles, 'restricted');
        $this->assertNotContains('Pečena piletina s avokadom i salatom', $titles, 'expired');

        foreach ($data['hook']['dishes'] as $dish) {
            $this->assertStringNotContainsString('€', $dish['big'].$dish['small']);
        }

        // The app's screen: the shop and the count, the sum, the old prices and the saving, said as what they are.
        $this->assertSame('Konzum · 4 sastojka na akciji', $data['hero']['chainLine']);
        $this->assertSame(['7,16 €', '11,66 €', 'Ušteda 4,50 €'], [$data['hero']['sum'], $data['hero']['old'], $data['hero']['saving']]);
        $this->assertSame('zbroj sastojaka na akciji', $data['hero']['sumLabel']);
        $this->assertSame('75 min · 4 porcije', $data['hero']['meta']);

        // The list is the dish's main ingredients by name; the end says where the recipe and the link are; the picture is labelled.
        $this->assertSame(['Pileći batak i zabatak', 'Brokula', 'Pečena paprika filet', 'Celer glava'], $data['list']['rows']);
        $this->assertSame('Recept i popis su u aplikaciji Listo.', $data['cta']['line']);
        $this->assertSame('Poveznica je u komentaru.', $data['cta']['link']);
        $this->assertSame('AI ilustracija', $data['aiLabel']);
        $this->assertSame('Slika jela je AI ilustracija.', $data['cta']['aiNote']);

        // Nothing of a leaflet's product is in what Chromium is given: no crop, no page, no price of an offer.
        $this->assertStringNotContainsString('/crops/', $script);
        $this->assertStringNotContainsString('/pages/', $script);
    }

    public function test_the_german_brand_gets_a_german_scene_and_the_number_hook_puts_the_saving_on_the_sticker(): void
    {
        $this->fakePictures();
        $brand = $this->recipeBrand('at', ['voice' => ['cta' => 'Hol dir Listo', 'app_name' => 'Listo', 'recipe_hook' => 'figure', ...self::SCENE]]);
        $item = $this->recipeItem($brand, $this->recipeFeedItem(0, 'at'), market: 'at');

        $dir = storage_path('app/tmp/recipe-bundle-test-at');
        File::deleteDirectory($dir);

        try {
            $bundles = app(RecipeSceneBundle::class);
            $shape = $bundles->shape($item, $brand);
            $data = $bundles->make($brand, $item, RecipeVideoPlan::build(array_values(RecipeCopy::voiceLines($item, $brand)), null, $shape['dishes'], $shape['rows']), 'description', $dir);
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame(['dishes' => 1, 'rows' => 0], $shape, 'no other dish to show, and the brand does not show ingredients');
        $this->assertSame('4 Zutaten im Angebot', $data['hook']['headline']);
        $this->assertSame(['2,70 €', 'Ersparnis lt. Prospekt'], [$data['hook']['dishes'][0]['big'], $data['hook']['dishes'][0]['small']]);
        $this->assertSame('Was kochen', $data['ui']['title']);
        $this->assertSame(['Prospekte', 'Suchen', 'Kochen', 'Listen', 'Einstellungen'], $data['ui']['tabs']);
        $this->assertSame('Sparen 2,70 €', $data['hero']['saving']);
        $this->assertSame('KI-Illustration', $data['aiLabel']);
        $this->assertSame('Listo', $data['brand']['app'], 'the app is not named for its market');
        $this->assertStringContainsString('Beschreibung', $data['cta']['link']);
        $this->assertSame([], $data['list']['rows']);
    }

    public function test_a_brand_keeps_the_three_slides_until_it_chooses_the_scene(): void
    {
        Queue::fake();

        $this->draft();

        Queue::assertPushed(RenderVideoJob::class, 1);
        Queue::assertNotPushed(RenderRecipeVideoJob::class);
    }

    public function test_a_brand_that_chose_the_scene_gets_one_scene_video_for_the_channels_that_say_the_same_about_the_link(): void
    {
        Queue::fake();

        $draft = $this->draft(self::SCENE, [Platform::InstagramBusiness, Platform::TikTok, Platform::YouTube], channelOptions: ['ig_business' => ['format' => 'video']]);
        $youtube = $draft->variants->firstWhere('platform', Platform::YouTube);

        Queue::assertNotPushed(RenderVideoJob::class);
        Queue::assertPushed(RenderRecipeVideoJob::class, 2);
        Queue::assertPushed(RenderRecipeVideoJob::class, fn (RenderRecipeVideoJob $job): bool => count($job->variantIds) === 2 && $job->where === 'comment' && ! $job->voiceover);
        Queue::assertPushed(RenderRecipeVideoJob::class, fn (RenderRecipeVideoJob $job): bool => $job->variantIds === [$youtube->id] && $job->where === 'description');
    }

    public function test_a_scene_video_the_draft_already_has_is_reused_and_a_slideshow_is_not_taken_for_one(): void
    {
        Queue::fake();

        $draft = $this->draft(self::SCENE, [Platform::TikTok], render: false);
        MediaAsset::factory()->for($draft->brand)->create(['post_draft_id' => $draft->id, 'template_key' => 'video/slideshow', 'format' => 'mp4', 'params' => ['link_in' => 'comment']]);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());
        Queue::assertPushed(RenderRecipeVideoJob::class, 1);

        $scene = MediaAsset::factory()->for($draft->brand)->create(['post_draft_id' => $draft->id, 'template_key' => RecipeVideoRenderer::TEMPLATE_KEY, 'format' => 'mp4', 'params' => ['link_in' => 'comment']]);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());
        Queue::assertPushed(RenderRecipeVideoJob::class, 1);
        $this->assertSame($scene->id, $draft->variants()->first()->media()->first()?->id);
    }

    /**
     * The whole path with the real renderers — Chromium draws the frames, ffmpeg mixes and encodes — and only the network faked.
     */
    #[Group('render')]
    public function test_the_scene_comes_out_in_the_shape_every_uploader_takes(): void
    {
        $chrome = (string) config('hub.render.chrome_path');

        if ($chrome !== '' && ! is_file($chrome)) {
            $this->markTestSkipped("No Chromium at {$chrome}");
        }

        Storage::fake('public');
        Storage::fake('local');
        Sleep::fake();
        config()->set('hub.media_disk', 'public');
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.disk', 'local');
        FakeSpeech::fake($this->requests);
        $this->fakePictures();

        $draft = $this->draft([...self::SCENE, 'recipe_hook' => 'question', 'recipe_highlights' => true], [Platform::TikTok], render: false, voiceover: true);
        $variant = $draft->variants->first();
        $item = $draft->contentItems->first();
        $this->recipeItem($draft->brand, $this->recipeFeedItem(1));

        RenderRecipeVideoJob::dispatchSync($draft->id, [$variant->id], voiceover: true);

        $asset = $variant->refresh()->media()->firstOrFail();
        $file = $asset->absolutePath();

        $this->assertTrue($asset->isVideo());
        $this->assertSame('video/recipe-scene', $asset->template_key);
        $this->assertSame([1080, 1920], [$asset->width, $asset->height]);
        $this->assertNull(app(FormatCheck::class)->problem($variant), 'it is ready to publish');

        $process = new Process(['ffprobe', '-v', 'error', '-show_streams', '-of', 'json', $file]);
        $process->mustRun();
        $streams = collect(json_decode($process->getOutput(), true)['streams']);
        $video = $streams->firstWhere('codec_type', 'video');
        $audio = $streams->firstWhere('codec_type', 'audio');

        $this->assertSame(['h264', 'yuv420p', '30/1'], [$video['codec_name'], $video['pix_fmt'], $video['r_frame_rate']]);
        $this->assertSame(['aac', '44100'], [$audio['codec_name'], $audio['sample_rate']]);
        $this->assertEqualsWithDelta((float) $asset->durationSeconds(), (float) $video['duration'], 0.1);
        $this->assertEqualsWithDelta((float) $asset->durationSeconds(), (float) $audio['duration'], 0.1);
        $this->assertGreaterThan(8.0, (float) $asset->durationSeconds());
        $this->assertLessThan(16.0, (float) $asset->durationSeconds());

        // The three sentences of a dish, spoken and kept with the video; two dishes in the hook, four rows on the list.
        $this->assertSame('ok', $asset->params['voiceover']['status']);
        // The voice is given the same three sentences as the slides are (its numbers spelled out, as every script's are).
        $lines = array_values(RecipeCopy::voiceLines($item, $draft->brand));
        $this->assertCount(3, $this->requests);
        $this->assertSame([$lines[0], $lines[2]], [$this->requests[0]['text'], $this->requests[2]['text']]);
        $this->assertStringContainsString('sastojaka je na akciji u trgovini Ribola', $this->requests[1]['text']);
        $this->assertCount(3, $asset->params['voiceover']['clips']);
        $this->assertSame(['comment', 'question', 2, 4], [$asset->params['link_in'], $asset->params['hook'], $asset->params['dishes'], $asset->params['rows']]);
        $this->assertLessThan($asset->params['frames']['frames'], $asset->params['frames']['unique'], 'the still end of the end card is drawn once');

        // The cover is the first dish with its sticker; TikTok takes the same moment.
        $this->assertSame('jpg', $asset->poster?->format);
        $this->assertSame(400, $variant->setting('cover_timestamp_ms'));

        $this->assertSame([], glob(storage_path('app/tmp/recipe-video-*')) ?: [], 'a render leaves no frames behind');
    }

    /**
     * @param  array<string, mixed>  $voice  Added to the brand's voice.
     * @param  list<Platform>  $platforms
     * @param  array<string, array<string, mixed>>  $channelOptions
     */
    private function draft(array $voice = [], array $platforms = [Platform::InstagramBusiness, Platform::TikTok], bool $render = true, bool $voiceover = false, array $channelOptions = []): PostDraft
    {
        $brand = $this->recipeBrand(attributes: [
            'voice' => ['cta' => 'Preuzmi Listo', 'hashtags' => ['listo'], ...$voice],
            'voiceover' => $voiceover ? ['enabled' => true, 'voice_id' => 'voice-1'] : null,
        ]);
        $item = $this->recipeItem($brand, $this->recipeFeedItem(0));

        return app(CreateDraft::class)->execute($item, $this->recipeAccounts($brand, $platforms), render: $render, channelOptions: $channelOptions);
    }

    private function fakePictures(): void
    {
        $image = imagecreatetruecolor(400, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 90, 60));
        imagefilledellipse($image, 200, 200, 300, 300, imagecolorallocate($image, 250, 240, 230));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        Http::fake(fn ($request) => str_contains($request->url(), 'elevenlabs') ? null : Http::response($png, 200, ['Content-Type' => 'image/png']));
    }
}
