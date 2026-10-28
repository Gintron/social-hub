<?php

declare(strict_types=1);

namespace App\Persona;

use App\Actions\CreateVideoPost;
use App\Ai\CaptionValidator;
use App\Enums\ActorType;
use App\Enums\ContentFormat;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\TopicIdea;
use App\Support\PostingSchedule;
use App\Video\FalClient;
use App\Video\FalException;
use App\Voiceover\NarrationClip;
use App\Voiceover\VoiceoverException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * An approved script becomes a short on the character's channels.
 *
 * The lines are spoken (ShortVoice); each run of lines with the same shot is one picture from fal — the character saying
 * them, lips moved by that very voice, or a scene while the voice goes on — and ffmpeg puts them one after another with
 * the words on screen (ShortComposer). The finished video goes into a post through CreateVideoPost, the same way a video
 * made elsewhere does, and waits there for a person's approval.
 *
 * fal is paid per request, so every request is written down (`topic_ideas.production`) the moment it is made: a try
 * that fails later polls the same request instead of paying again, and a shot whose words, picture and voice did not
 * change is reused when a person edits another line and asks again. Everything that can refuse — the caption, the
 * channels, the character's picture, the cost — is checked before the first paid call.
 */
final class ShortProducer
{
    private const DISK = 'local';

    public function __construct(
        private readonly FalClient $fal,
        private readonly ShortVoice $voice,
        private readonly ShortComposer $composer,
        private readonly CaptionValidator $validator,
        private readonly CreateVideoPost $posts,
        private readonly PostingSchedule $schedule,
    ) {}

    /**
     * Runs of lines with the same shot, in order: each is one picture.
     *
     * @return list<array{shot: string, lines: list<int>}>
     */
    public static function runs(ShortScript $script): array
    {
        $runs = [];

        foreach ($script->lines as $index => $line) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['shot'] === $line['shot']) {
                $runs[$last]['lines'][] = $index;
            } else {
                $runs[] = ['shot' => $line['shot'], 'lines' => [$index]];
            }
        }

        return $runs;
    }

    /**
     * What a short of this script would cost at fal and how long it would run, from the words alone — before anything is
     * spoken or paid for.
     *
     * @return array{seconds: int, cost: float}
     */
    public static function estimate(ShortScript $script, string $language): array
    {
        $pace = max(0.5, (float) config("hub.persona.words_per_second.{$language}", 2.5));
        $seconds = [];

        foreach (self::runs($script) as $run) {
            $words = 0;

            foreach ($run['lines'] as $line) {
                $words += count(preg_split('/\s+/u', $script->lines[$line]['text'], flags: PREG_SPLIT_NO_EMPTY) ?: []);
            }

            $seconds[] = ['shot' => $run['shot'], 'seconds' => $words / $pace + (count($run['lines']) - 1) * ShortComposer::GAP_SECONDS];
        }

        return [
            'seconds' => (int) ceil(array_sum(array_column($seconds, 'seconds'))),
            'cost' => self::cost($seconds),
        ];
    }

    /**
     * Does what can be done now: speaks, asks fal for what is missing, takes what fal has finished and, once every shot is
     * there, makes the video and its post. Null means fal is still at it — ask again later; nothing is paid twice.
     *
     * @throws InvalidArgumentException When the short cannot be made as asked; nothing has been paid for at fal then.
     * @throws VoiceoverException
     * @throws FalException
     */
    public function produce(TopicIdea $idea): ?PostDraft
    {
        $brand = $idea->brand;
        $persona = $brand->personaSettings();
        $script = $idea->shortScript() ?? throw new InvalidArgumentException('Tema nema skriptu.');
        $runs = self::runs($script);

        $this->assertCaption($script);
        $channels = $this->channels($idea, $script);
        $image = $this->characterImage($persona, $runs);

        if (! $this->fal->configured()) {
            throw new InvalidArgumentException('FAL_KEY nije postavljen; video se ne može napraviti.');
        }

        $clips = $this->voice->speak($brand, $persona, $script);
        $folder = "persona/{$idea->id}";
        Storage::disk(self::DISK)->makeDirectory($folder);

        $previous = [];

        foreach ((array) (($idea->production ?? [])['shots'] ?? []) as $shot) {
            if (is_array($shot) && isset($shot['key'])) {
                $previous[$shot['key']] = $shot;
            }
        }

        $shots = [];

        foreach ($runs as $i => $run) {
            $lineClips = array_map(fn (int $line): NarrationClip => $clips[$line], $run['lines']);
            $audio = "{$folder}/voice-{$i}.mp3";
            $seconds = $this->composer->voice($lineClips, $this->path($audio));
            $key = $this->key($run, $script, $lineClips, $persona, $image);

            $shots[] = [
                ...array_intersect_key($previous[$key] ?? [], array_flip(['request', 'video'])),
                'key' => $key,
                'shot' => $run['shot'],
                'lines' => $run['lines'],
                'audio' => $audio,
                'seconds' => round($seconds, 3),
                'clips' => array_map(fn (NarrationClip $clip): float => $clip->seconds, $lineClips),
            ];
        }

        $cost = self::cost($shots);
        $ceiling = (float) config('fal.max_cost_per_video', 8.0);

        if ($cost > $ceiling) {
            throw new InvalidArgumentException(sprintf('Video bi na falu stajao oko %.2f $, a granica je %.2f $; skrati skriptu ili smanji međukadrove.', $cost, $ceiling));
        }

        $this->save($idea, $shots, $cost);

        // Every request goes out before any is waited for: fal makes them side by side.
        foreach ($shots as $i => $shot) {
            if ($this->hasVideo($shot) || isset($shot['request'])) {
                continue;
            }

            $shots[$i]['request'] = $this->submit($shot, $script, $persona, $image);
            $this->save($idea, $shots, $cost);
        }

        $waiting = false;

        foreach ($shots as $i => $shot) {
            if ($this->hasVideo($shot)) {
                continue;
            }

            try {
                $result = $this->fal->check($shot['request']);
            } catch (FalException $e) {
                // A request fal gave up on is not asked about again; the next try (a person's) makes a new one.
                if (! $e->retryable) {
                    unset($shots[$i]['request']);
                    $this->save($idea, $shots, $cost);
                }

                throw $e;
            }

            if ($result === null) {
                $waiting = true;

                continue;
            }

            $url = $result['video']['url'] ?? null;

            if (! is_string($url) || $url === '') {
                unset($shots[$i]['request']);
                $this->save($idea, $shots, $cost);

                throw new FalException('fal je završio bez videa.', 'failed');
            }

            $video = "{$folder}/shot-{$i}-".mb_substr($shot['key'], 0, 12).'.mp4';
            $this->fal->download($url, $this->path($video));
            $shots[$i]['video'] = $video;
            $this->save($idea, $shots, $cost);
        }

        if ($waiting) {
            return null;
        }

        $output = "{$folder}/short-".now()->format('YmdHis').'.mp4';
        $cover = "{$folder}/cover.jpg";

        $this->composer->compose(
            array_map(fn (array $shot): array => ['video' => $this->path($shot['video']), 'audio' => $this->path($shot['audio']), 'seconds' => (float) $shot['seconds']], $shots),
            ShortComposer::captions(array_column($script->lines, 'text'), $this->lineStarts($shots), array_merge(...array_column($shots, 'clips'))),
            $brand->audioTrackPath('auto', $idea->id),
            $brand->voiceoverSettings()->musicGainDb(),
            $this->path($output),
        );
        $this->composer->cover($this->path($output), $this->path($cover));

        return $this->posts->execute(
            brand: $brand,
            videoPath: $this->path($output),
            title: $script->title,
            channels: $channels,
            url: (string) $brand->site_url,
            coverPath: $this->path($cover),
            aiGenerated: true,
            scheduledAt: $this->schedule->nextSlot($brand, CarbonImmutable::now()->addHour()),
            status: DraftStatus::PendingApproval,
            actor: ActorType::Agent,
        );
    }

    /**
     * @param  list<array{shot: string, seconds: float}>  $shots
     */
    private static function cost(array $shots): float
    {
        $cost = 0.0;

        foreach ($shots as $shot) {
            $seconds = $shot['shot'] === 'broll' ? self::brollSeconds((float) $shot['seconds']) : (float) $shot['seconds'];
            $cost += $seconds * (float) config("fal.price_per_second.{$shot['shot']}", 0.1);
        }

        return round($cost, 2);
    }

    /**
     * The scene model makes whole seconds within its range; a longer line holds the last frame.
     */
    private static function brollSeconds(float $seconds): int
    {
        return max((int) config('fal.broll_seconds.min', 3), min((int) config('fal.broll_seconds.max', 15), (int) ceil($seconds)));
    }

    /**
     * When each line starts in the finished video.
     *
     * @param  list<array{seconds: float, clips: list<float>}>  $shots
     * @return list<float>
     */
    private function lineStarts(array $shots): array
    {
        $starts = [];
        $shotStart = 0.0;

        foreach ($shots as $shot) {
            $at = $shotStart;

            foreach ($shot['clips'] as $seconds) {
                $starts[] = $at;
                $at += $seconds + ShortComposer::GAP_SECONDS;
            }

            $shotStart += (float) $shot['seconds'];
        }

        return $starts;
    }

    /**
     * @param  array{shot: string, lines: list<int>, audio: string, seconds: float}  $shot
     * @return array{request_id: string, status_url: string, response_url: string}
     *
     * @throws FalException
     */
    private function submit(array $shot, ShortScript $script, Persona $persona, ?string $image): array
    {
        $visual = implode(' ', array_filter(array_map(fn (int $line): string => $script->lines[$line]['visual'], $shot['lines'])));

        if ($shot['shot'] === 'talking') {
            return $this->fal->submit((string) config('fal.models.talking'), [
                'image_url' => FalClient::dataUri((string) $image, (string) (mime_content_type((string) $image) ?: 'image/jpeg')),
                'audio_url' => FalClient::dataUri($this->path($shot['audio']), 'audio/mpeg'),
                'prompt' => mb_substr(mb_trim("The person talks to the camera, natural and friendly. {$visual} Setting: {$persona->ambience}"), 0, 1500),
            ]);
        }

        return $this->fal->submit((string) config('fal.models.broll'), [
            'prompt' => mb_substr(mb_trim($visual !== '' ? $visual : $script->lines[$shot['lines'][0]]['text']).' Vertical video, natural light, realistic.', 0, 2400),
            'negative_prompt' => 'text, subtitles, captions, logo, watermark, blur, distortion, low quality',
            'aspect_ratio' => '9:16',
            'duration' => (string) self::brollSeconds((float) $shot['seconds']),
            'generate_audio' => false,
        ]);
    }

    /**
     * What a shot is made of: if none of it changed, the picture fal already made still fits.
     *
     * @param  array{shot: string, lines: list<int>}  $run
     * @param  list<NarrationClip>  $clips
     */
    private function key(array $run, ShortScript $script, array $clips, Persona $persona, ?string $image): string
    {
        return hash('sha256', json_encode([
            $run['shot'],
            array_map(fn (int $line): array => [$script->lines[$line]['text'], $script->lines[$line]['visual']], $run['lines']),
            array_map(fn (NarrationClip $clip): int => $clip->voiceoverId, $clips),
            (string) config("fal.models.{$run['shot']}"),
            $run['shot'] === 'talking' ? [hash_file('sha256', (string) $image), $persona->ambience] : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The caption repeats nothing the script does not say: an amount, a percentage or a link the voice never said is
     * the model's own, and CaptionValidator is the last gate for that, here as for every caption.
     *
     * @throws InvalidArgumentException
     */
    private function assertCaption(ShortScript $script): void
    {
        $said = new ContentItem(['title' => $script->title, 'body_text' => $script->spokenText(), 'facts' => [], 'badges' => []]);
        $violations = $this->validator->violationsInText($script->caption.' '.$script->hashtags, [$said]);

        if ($violations !== []) {
            throw new InvalidArgumentException('Tekst objave kaže nešto čega u skripti nema: '.implode(' ', $violations));
        }
    }

    /**
     * Every channel of the character that takes a video, with the words for it.
     *
     * @return array<string, array{caption: string}>
     *
     * @throws InvalidArgumentException
     */
    private function channels(TopicIdea $idea, ShortScript $script): array
    {
        $platforms = SocialAccount::query()->where('brand_id', $idea->brand_id)->active()->get()
            ->map(fn (SocialAccount $account): Platform => $account->platform)
            ->filter(fn (Platform $platform): bool => ! $platform->isManual() && in_array(ContentFormat::Video, $platform->formats(), true))
            ->unique(fn (Platform $platform): string => $platform->value);

        if ($platforms->isEmpty()) {
            throw new InvalidArgumentException('Lik nema nijedan aktivan kanal koji prima video (TikTok, Instagram, Facebook stranica, YouTube).');
        }

        $text = mb_trim($script->caption."\n\n".$script->hashtags);
        $channels = [];

        foreach ($platforms as $platform) {
            $channels[$platform->value] = ['caption' => $platform === Platform::YouTube
                ? $script->title."\n".$text.(str_contains(mb_strtolower($text), '#shorts') ? '' : ' #shorts')
                : $text];
        }

        return $channels;
    }

    /**
     * The picture of the character, when a shot needs the character.
     *
     * @param  list<array{shot: string}>  $runs
     *
     * @throws InvalidArgumentException
     */
    private function characterImage(Persona $persona, array $runs): ?string
    {
        if (! in_array('talking', array_column($runs, 'shot'), true)) {
            return null;
        }

        $path = $persona->appearanceImage === null ? null : Storage::disk('public')->path($persona->appearanceImage);

        if ($path === null || ! is_file($path)) {
            throw new InvalidArgumentException('Lik nema sliku (Brendovi → AI lik → Slika lika), a skripta ima kadrove u kojima govori.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $shot
     */
    private function hasVideo(array $shot): bool
    {
        return isset($shot['video']) && is_file($this->path((string) $shot['video']));
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     */
    private function save(TopicIdea $idea, array $shots, float $cost): void
    {
        $idea->forceFill(['production' => ['shots' => $shots, 'estimated_cost' => $cost]])->save();
    }

    private function path(string $relative): string
    {
        return Storage::disk(self::DISK)->path($relative);
    }
}
