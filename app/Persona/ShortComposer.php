<?php

declare(strict_types=1);

namespace App\Persona;

use App\Voiceover\AudioProbe;
use App\Voiceover\NarrationClip;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ffmpeg's part of a short: the voice of a run of lines as one file (what fal moves the lips to), and the finished video —
 * the shots one after another in 1080×1920, the voice over them, the brand's music pressed down under it, and the words
 * on screen as they are said.
 *
 * The output is what every uploader takes: H.264/yuv420p + AAC, `faststart`.
 */
final class ShortComposer
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const FPS = 30;

    /** A breath between two lines of the same shot. */
    public const GAP_SECONDS = 0.18;

    /** At most this many words are on screen at once. */
    private const WORDS_PER_CAPTION = 4;

    public function __construct(private readonly AudioProbe $probe) {}

    /**
     * When each line's words are on screen: a line is cut into a few words at a time, each shown for its share of the
     * line by length — close enough to the voice without asking for word timings.
     *
     * @param  list<string>  $texts  What is said, line by line (as written, not spelled out).
     * @param  list<float>  $starts  When each line starts in the video.
     * @param  list<float>  $seconds  How long each line is spoken.
     * @return list<array{text: string, start: float, end: float}>
     */
    public static function captions(array $texts, array $starts, array $seconds): array
    {
        $captions = [];

        foreach ($texts as $i => $text) {
            $words = preg_split('/\s+/u', mb_trim($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];
            $chunks = array_map(fn (array $chunk): string => implode(' ', $chunk), array_chunk($words, self::WORDS_PER_CAPTION));
            $length = max(1, array_sum(array_map(mb_strlen(...), $chunks)));
            $at = $starts[$i];

            foreach ($chunks as $chunk) {
                $duration = $seconds[$i] * mb_strlen($chunk) / $length;
                $captions[] = ['text' => $chunk, 'start' => $at, 'end' => $at + $duration];
                $at += $duration;
            }
        }

        return $captions;
    }

    /**
     * The lines of one shot as one MP3, each brought to the same loudness, with a breath between them.
     *
     * @param  list<NarrationClip>  $clips
     * @return float Its length in seconds.
     */
    public function voice(array $clips, string $output): float
    {
        $arguments = ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'];
        $parts = [];
        $labels = '';

        foreach ($clips as $i => $clip) {
            $arguments = [...$arguments, '-i', $clip->path];
            $pad = $i < count($clips) - 1 ? sprintf(',apad=pad_dur=%.3f', self::GAP_SECONDS) : '';
            $parts[] = sprintf('[%d:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=mono,volume=%.1fdB%s[l%d]', $i, $clip->gainDb, $pad, $i);
            $labels .= "[l{$i}]";
        }

        $parts[] = sprintf('%sconcat=n=%d:v=0:a=1,alimiter=limit=0.89:level=0[aout]', $labels, count($clips));

        $this->run([...$arguments, '-filter_complex', implode(';', $parts), '-map', '[aout]', '-c:a', 'libmp3lame', '-b:a', '128k', '-ar', '44100', $output]);

        return $this->probe->duration($output);
    }

    /**
     * @param  list<array{video: string, audio: string, seconds: float}>  $shots  In order; each shot lasts as long as its voice.
     * @param  list<array{text: string, start: float, end: float}>  $captions  The words on screen and when.
     */
    public function compose(array $shots, array $captions, ?string $music, float $musicGainDb, string $output): void
    {
        $total = array_sum(array_column($shots, 'seconds'));
        $arguments = ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'];
        $parts = [];
        $video = '';
        $voice = '';
        $count = count($shots);

        foreach ($shots as $i => $shot) {
            $arguments = [...$arguments, '-i', $shot['video']];
            // Cover the frame, and hold the last frame when the model's clip is shorter than the line.
            $parts[] = sprintf(
                '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=increase,crop=%2$d:%3$d,setsar=1,fps=%4$d,'
                .'tpad=stop_mode=clone:stop_duration=%5$.3f,trim=duration=%5$.3f,setpts=PTS-STARTPTS[s%1$d]',
                $i, self::WIDTH, self::HEIGHT, self::FPS, $shot['seconds'],
            );
            $video .= "[s{$i}]";
        }

        foreach ($shots as $i => $shot) {
            $arguments = [...$arguments, '-i', $shot['audio']];
            $parts[] = sprintf('[%d:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,apad=whole_dur=%.3f,atrim=0:%.3f[a%d]', $count + $i, $shot['seconds'], $shot['seconds'], $i);
            $voice .= "[a{$i}]";
        }

        $subtitles = tempnam(sys_get_temp_dir(), 'short-').'.ass';
        file_put_contents($subtitles, $this->ass($captions));

        $parts[] = sprintf('%sconcat=n=%d:v=1:a=0[cat]', $video, $count);
        $parts[] = sprintf("[cat]subtitles=filename='%s':fontsdir='%s',format=yuv420p[out]", $this->escape($subtitles), $this->escape(resource_path('catalog-video/fonts')));
        $parts[] = sprintf('%sconcat=n=%d:v=0:a=1[said]', $voice, $count);

        if ($music !== null) {
            $arguments = [...$arguments, '-stream_loop', '-1', '-i', $music];
            $fadeOut = min(2.0, $total / 3);
            $parts[] = sprintf(
                '[%d:a]atrim=0:%.3f,asetpts=N/SR/TB,loudnorm=I=-16:TP=-1.5:LRA=11,aresample=44100,volume=%.1fdB,'
                .'afade=t=in:st=0:d=0.3,afade=t=out:st=%.3f:d=%.3f,aformat=sample_fmts=fltp:channel_layouts=stereo[bed]',
                $count * 2, $total, $musicGainDb, max(0.0, $total - $fadeOut), $fadeOut,
            );
            $parts[] = '[said]asplit=2[voice][key]';
            $parts[] = '[bed][key]sidechaincompress=threshold=0.03:ratio=4:attack=25:release=700:makeup=1[ducked]';
            $parts[] = sprintf('[ducked][voice]amix=inputs=2:duration=longest:dropout_transition=0:normalize=0,alimiter=limit=0.89:level=0,atrim=0:%.3f[aout]', $total);
        } else {
            $parts[] = '[said]alimiter=limit=0.89:level=0[aout]';
        }

        try {
            $this->run([...$arguments,
                '-filter_complex', implode(';', $parts),
                '-map', '[out]', '-map', '[aout]',
                '-c:v', 'libx264', '-preset', 'medium', '-crf', '21', '-pix_fmt', 'yuv420p', '-r', (string) self::FPS,
                '-c:a', 'aac', '-b:a', '128k', '-ar', '44100',
                '-t', sprintf('%.3f', $total),
                '-movflags', '+faststart',
                $output,
            ]);
        } finally {
            @unlink($subtitles);
        }
    }

    /**
     * A frame a second in, as the post's cover.
     */
    public function cover(string $video, string $output): void
    {
        $this->run(['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error', '-ss', '1', '-i', $video, '-frames:v', '1', '-q:v', '3', $output]);
    }

    /**
     * @param  list<array{text: string, start: float, end: float}>  $captions
     */
    private function ass(array $captions): string
    {
        $lines = [
            '[Script Info]',
            'ScriptType: v4.00+',
            'PlayResX: '.self::WIDTH,
            'PlayResY: '.self::HEIGHT,
            'WrapStyle: 0',
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            // White on a black outline, lower third, clear of the networks' own buttons and captions.
            'Style: Default,Figtree ExtraBold,78,&H00FFFFFF,&H00FFFFFF,&H00000000,&H64000000,-1,0,0,0,100,100,0,0,1,7,2,2,90,90,560,1',
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
        ];

        foreach ($captions as $caption) {
            $text = str_replace(['\\', '{', '}', "\n"], ['', '(', ')', ' '], $caption['text']);
            $lines[] = sprintf('Dialogue: 0,%s,%s,Default,,0,0,0,,%s', $this->time($caption['start']), $this->time($caption['end']), $text);
        }

        return implode("\n", $lines)."\n";
    }

    private function time(float $seconds): string
    {
        $centiseconds = (int) round($seconds * 100);

        return sprintf('%d:%02d:%02d.%02d', intdiv($centiseconds, 360000), intdiv($centiseconds, 6000) % 60, intdiv($centiseconds, 100) % 60, $centiseconds % 100);
    }

    /**
     * A path inside an ffmpeg filter argument.
     */
    private function escape(string $path): string
    {
        return str_replace(['\\', ':', "'"], ['\\\\', '\\:', "\\'"], $path);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): void
    {
        $process = new Process($arguments, timeout: (int) config('hub.render.video_timeout', 300));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg nije uspio: '.mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 500));
        }
    }
}
