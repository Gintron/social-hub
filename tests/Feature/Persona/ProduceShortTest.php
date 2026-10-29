<?php

declare(strict_types=1);

namespace Tests\Feature\Persona;

use App\Actions\ProduceShort;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\TopicStatus;
use App\Jobs\ProduceShortJob;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\TopicIdea;
use App\Persona\ShortProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSpeech;
use Tests\TestCase;

/**
 * From an approved script to a post: the voice is spoken, every run of lines is one picture from fal (faked here, the
 * request is the real one), ffmpeg makes the vertical video for real, and the post waits for approval on the
 * character's channels. What fal was asked for is kept, so nothing is paid twice, and nothing is asked of fal when the
 * short cannot be made anyway.
 */
final class ProduceShortTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $speech = [];

    /** @var list<array{model: string, input: array<string, mixed>}> */
    private array $submitted = [];

    private string $status = 'COMPLETED';

    private int $resultStatus = 200;

    private static ?string $clip = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Sleep::fake();
        config()->set('hub.media_disk', 'public');
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.disk', 'local');
        config()->set('fal.key', 'fal-test');
        config()->set('openai.api_key', 'test-key');

        $this->fakeServices();
    }

    public function test_an_approved_script_becomes_a_vertical_video_in_a_post_waiting_for_approval(): void
    {
        $idea = $this->approvedIdea();
        $idea->forceFill(['script' => [...$idea->script, 'caption' => 'Spend $5 less. The 24-hour rule.']])->save();

        $this->make($idea);
        $this->assertSame(TopicStatus::Produced, $idea->status, (string) $idea->production_error);

        // Talking, scene, talking: three pictures, the scene a vertical clip without its own sound.
        $this->assertSame(['fal-ai/kling-video/ai-avatar/v2/standard', 'fal-ai/kling-video/v3/standard/text-to-video', 'fal-ai/kling-video/ai-avatar/v2/standard'], array_column($this->submitted, 'model'));
        $this->assertStringStartsWith('data:image/', $this->submitted[0]['input']['image_url']);
        $this->assertStringStartsWith('data:audio/mpeg;base64,', $this->submitted[0]['input']['audio_url']);
        $this->assertStringContainsString('bright small kitchen', $this->submitted[0]['input']['prompt']);
        $this->assertSame('9:16', $this->submitted[1]['input']['aspect_ratio']);
        $this->assertFalse($this->submitted[1]['input']['generate_audio']);
        $this->assertStringStartsWith('a cart full of boxes', $this->submitted[1]['input']['prompt']);

        // The voice was asked for in words: "$5" never reaches it.
        $this->assertSame('Spend five dollars less today.', $this->speech[1]['text']);

        $draft = PostDraft::query()->with(['variants', 'variants.media'])->findOrFail($idea->post_draft_id);
        $this->assertSame(DraftStatus::PendingApproval, $draft->status);
        $this->assertNotNull($draft->scheduled_at);
        $this->assertSame([Platform::TikTok, Platform::YouTube], $draft->variants->pluck('platform')->sortBy('value')->values()->all());

        $youtube = $draft->variants->firstWhere('platform', Platform::YouTube);
        $tiktok = $draft->variants->firstWhere('platform', Platform::TikTok);
        $this->assertSame("Wait a day before you buy\nSpend $5 less. The 24-hour rule.\n\n#money #habits #shorts", $youtube->caption);
        $this->assertSame("Spend $5 less. The 24-hour rule.\n\n#money #habits", $tiktok->caption);
        $this->assertTrue($tiktok->aiGenerated());
        $this->assertSame('video', $tiktok->settings['format']);

        $video = $tiktok->media->firstOrFail();
        $this->assertSame(1080, $video->width);
        $this->assertSame(1920, $video->height);
        $streams = $this->streams($video->absolutePath());
        $this->assertSame(['h264', 'aac'], array_column($streams, 'codec_name'));
        $this->assertSame('yuv420p', $streams[0]['pix_fmt']);
    }

    public function test_fal_still_working_is_asked_again_later_and_never_paid_twice(): void
    {
        $idea = $this->approvedIdea();
        $this->status = 'IN_PROGRESS';

        app(ProduceShort::class)->execute($idea);

        $idea->refresh();
        $this->assertSame(TopicStatus::Producing, $idea->status);
        $this->assertCount(3, $this->submitted);
        $this->assertCount(3, array_filter(array_column($idea->production['shots'], 'request')));
        $this->assertNull($idea->post_draft_id);

        $this->status = 'COMPLETED';
        app(ShortProducer::class)->produce($idea);

        $this->assertCount(3, $this->submitted, 'the same requests are asked about, none is made again');
    }

    public function test_the_video_is_put_together_in_a_run_of_its_own_and_the_voice_once(): void
    {
        $idea = $this->approvedIdea();

        // The first run asks fal and takes what it made; the encode waits for the next run.
        app(ProduceShort::class)->execute($idea);

        $idea->refresh();
        $this->assertSame(TopicStatus::Producing, $idea->status);
        $this->assertCount(3, array_filter(array_column($idea->production['shots'], 'video')));
        $this->assertArrayNotHasKey('output', $idea->production);

        $voice = Storage::disk('local')->path($idea->production['shots'][0]['audio']);
        touch($voice, time() - 600);
        clearstatcache();
        $before = filemtime($voice);

        $this->make($idea);

        clearstatcache();
        $this->assertSame(TopicStatus::Produced, $idea->status, (string) $idea->production_error);
        $this->assertSame($before, filemtime($voice), 'the voice of a shot is not put together again');
        $this->assertCount(3, $this->submitted);
    }

    public function test_a_shot_that_did_not_change_is_reused_when_the_video_is_asked_for_again(): void
    {
        $idea = $this->approvedIdea();
        $this->make($idea);
        $this->assertCount(3, $this->submitted);

        $idea->refresh()->forceFill(['status' => TopicStatus::VideoFailed])->save();
        $script = $idea->script;
        $script['lines'][2]['text'] = 'What did you skip this week? Tell me.';
        $idea->forceFill(['script' => $script])->save();

        $this->make($idea);

        $this->assertSame(TopicStatus::Produced, $idea->status, (string) $idea->production_error);
        $this->assertCount(4, $this->submitted, 'only the changed shot is made again');
    }

    public function test_a_request_fal_gave_up_on_fails_the_video_and_the_next_try_asks_anew(): void
    {
        $idea = $this->approvedIdea();
        $this->resultStatus = 500;

        app(ProduceShort::class)->execute($idea);

        $idea->refresh();
        $this->assertSame(TopicStatus::VideoFailed, $idea->status);
        $this->assertStringContainsString('Model crashed', (string) $idea->production_error);
        $this->assertArrayNotHasKey('request', $idea->production['shots'][0]);

        $this->resultStatus = 200;
        $this->make($idea);

        $this->assertSame(TopicStatus::Produced, $idea->status, (string) $idea->production_error);
        $this->assertCount(4, $this->submitted, 'only the shot fal gave up on is asked for again');
    }

    public function test_nothing_is_asked_of_fal_when_the_short_cannot_be_made(): void
    {
        $cases = [
            'the caption says what the script does not' => fn (TopicIdea $idea) => $idea->forceFill(['script' => [...$idea->script, 'caption' => 'Save 40% now.']])->save(),
            'the caption says a dollar amount the script does not' => fn (TopicIdea $idea) => $idea->forceFill(['script' => [...$idea->script, 'caption' => 'Save $500 a month.']])->save(),
            'a line would read a web address aloud' => function (TopicIdea $idea): void {
                $script = $idea->script;
                $script['lines'][0]['text'] = 'Go to https://example.com now.';
                $idea->forceFill(['script' => $script])->save();
            },
            'the character has no picture' => fn (TopicIdea $idea) => $idea->brand->forceFill(['persona' => [...$idea->brand->persona, 'appearance_image' => null]])->save(),
            'no channel takes a video' => fn (TopicIdea $idea) => SocialAccount::query()->where('brand_id', $idea->brand_id)->delete(),
            'the estimate is over the ceiling' => fn () => config()->set('fal.max_cost_per_video', 0.10),
        ];

        foreach ($cases as $case => $break) {
            $idea = $this->approvedIdea();
            $break($idea);

            app(ProduceShort::class)->execute($idea->refresh());

            $idea->refresh();
            $this->assertSame(TopicStatus::VideoFailed, $idea->status, $case);
            $this->assertNotEmpty($idea->production_error, $case);
            $this->assertSame([], $this->submitted, $case);
            config()->set('fal.max_cost_per_video', 8.0);
        }
    }

    public function test_the_estimate_counts_each_shot_at_its_own_price(): void
    {
        $idea = $this->approvedIdea();

        $estimate = ShortProducer::estimate($idea->shortScript(), 'en');

        $this->assertGreaterThan(0, $estimate['seconds']);
        // The scene is billed for at least three seconds even when its line is shorter.
        $this->assertGreaterThanOrEqual(round(3 * (float) config('fal.price_per_second.broll'), 2), $estimate['cost']);
    }

    /**
     * Ask for the video (unless it is already being made) and run the job the way the worker would: again and again
     * while it comes back, a few times at most.
     */
    private function make(TopicIdea $idea): TopicIdea
    {
        if ($idea->status->canProduce()) {
            app(ProduceShort::class)->execute($idea);
        }

        for ($run = 0; $run < 5 && $idea->refresh()->status === TopicStatus::Producing; $run++) {
            (new ProduceShortJob($idea->id))->handle(app(ShortProducer::class));
        }

        return $idea->refresh();
    }

    private function approvedIdea(): TopicIdea
    {
        Storage::disk('public')->put('brands/persona/mia.jpg', $this->jpeg());

        $brand = Brand::factory()->persona(['appearance_image' => 'brands/persona/mia.jpg'])->create([
            'voiceover' => ['voice_id' => 'voice-1'],
            'site_url' => null,
        ]);
        SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]);
        SocialAccount::factory()->for($brand)->create(['platform' => Platform::YouTube, 'refresh_token' => 'r']);

        return TopicIdea::factory()->for($brand)->create([
            'status' => TopicStatus::Approved,
            'script' => [
                'title' => 'Wait a day before you buy',
                'lines' => [
                    ['text' => 'Stop. Wait a day.', 'shot' => 'talking', 'visual' => 'raises a hand'],
                    ['text' => 'Spend $5 less today.', 'shot' => 'broll', 'visual' => 'a cart full of boxes'],
                    ['text' => 'What did you skip this week?', 'shot' => 'talking', 'visual' => 'leans in'],
                ],
                'caption' => 'The 24-hour rule.',
                'hashtags' => '#money #habits',
            ],
        ]);
    }

    private function fakeServices(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => fn (Request $request) => $this->speak($request),
            'queue.fal.run/*' => function (Request $request) {
                $path = mb_substr((string) parse_url($request->url(), PHP_URL_PATH), 1);

                if (str_ends_with($path, '/status')) {
                    return Http::response(['status' => $this->status]);
                }

                if (str_contains($path, '/requests/')) {
                    return $this->resultStatus === 200
                        ? Http::response(['video' => ['url' => 'https://v3.fal.media/files/shot.mp4']])
                        : Http::response(['detail' => 'Model crashed'], $this->resultStatus);
                }

                $this->submitted[] = ['model' => $path, 'input' => $request->data()];
                $id = 'req-'.count($this->submitted);

                return Http::response([
                    'request_id' => $id,
                    'status_url' => "https://queue.fal.run/{$path}/requests/{$id}/status",
                    'response_url' => "https://queue.fal.run/{$path}/requests/{$id}",
                ]);
            },
            'v3.fal.media/*' => fn () => Http::response($this->clip()),
        ]);
    }

    private function speak(Request $request): mixed
    {
        $this->speech[] = $request->data();
        $text = (string) ($request->data()['text'] ?? '');

        return Http::response(FakeSpeech::mp3(max(0.8, round(mb_strlen($text) / FakeSpeech::CHARACTERS_PER_SECOND, 1))), 200, [
            'request-id' => 'req-'.md5($text),
            'character-cost' => (string) FakeSpeech::cost($text),
        ]);
    }

    /**
     * What fal sends back: a small square clip, so the composer has to fit it into the vertical frame.
     */
    private function clip(): string
    {
        if (self::$clip !== null) {
            return self::$clip;
        }

        $file = tempnam(sys_get_temp_dir(), 'fal-').'.mp4';
        (new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=480x480:rate=30:duration=2', '-pix_fmt', 'yuv420p', $file]))->mustRun();
        self::$clip = (string) file_get_contents($file);
        @unlink($file);

        return self::$clip;
    }

    private function jpeg(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mia-').'.jpg';
        (new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=orange:size=720x1280', '-frames:v', '1', $file]))->mustRun();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function streams(string $file): array
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name,pix_fmt', '-of', 'json', $file]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['streams'];
    }
}
