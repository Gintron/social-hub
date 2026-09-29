<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Rendering\ImageRenderer;
use App\Rendering\Scene;
use App\Rendering\TemplateRegistry;
use App\Rendering\VideoRenderer;
use App\Voiceover\Narrator;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/** Render a reviewable campaign from approved copy and screenshots; never queue or publish it. */
final class RenderStoryboard extends Command
{
    protected $signature = 'hub:render-storyboard {manifest : JSON file with approved slides}
        {--brand= : Existing brand slug} {--output= : Directory for the MP4 and review images}';

    protected $description = 'Render a campaign preview with explicit slide timings, without scheduling or publishing';

    public function handle(ImageRenderer $images, VideoRenderer $video, TemplateRegistry $templates, Narrator $narrator): int
    {
        try {
            $path = realpath((string) $this->argument('manifest'));
            if ($path === false) {
                throw new RuntimeException('Datoteka scenarija ne postoji.');
            }

            $input = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            $data = Validator::make((array) $input, [
                'slides' => ['required', 'array', 'min:2', 'max:8'],
                'slides.*.template' => ['required', 'string'],
                'slides.*.seconds' => ['required', 'numeric', 'between:1.5,10'],
                'slides.*.data' => ['present', 'array'],
                'slides.*.image' => ['nullable', 'string'],
                // What the brand's voice says over the slide; approved with the rest of the copy.
                'slides.*.say' => ['nullable', 'string', 'max:300'],
                'audio' => ['nullable', 'string'],
                'voice' => ['sometimes', 'array'],
            ])->validate();
            $brand = Brand::query()->where('slug', (string) $this->option('brand'))->firstOrFail();
            // Preview overrides are not saved to the brand or applied to live campaigns.
            $brand->voice = [...($brand->voice ?? []), ...Arr::only($data['voice'] ?? [], ['cta', 'pitch', 'cta_note', 'activation'])];
            $output = (string) ($this->option('output') ?: dirname($path).'/preview');
            File::ensureDirectoryExists($output);

            // Validate the whole storyboard before rendering any assets.
            $scenes = [];
            foreach ($data['slides'] as $scene) {
                $template = $templates->get($scene['template']);
                if ($template['width'] !== VideoRenderer::WIDTH || $template['height'] !== VideoRenderer::HEIGHT) {
                    throw new RuntimeException('Video scenarij treba predloške 1080 × 1920.');
                }
                if (filled($scene['image'] ?? null)) {
                    $file = $this->localPath(dirname($path), $scene['image']);
                    $mime = File::mimeType($file);
                    if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                        throw new RuntimeException('Slika mora biti PNG, JPEG ili WebP.');
                    }
                    $scene['data']['primary_image'] = 'data:'.$mime.';base64,'.base64_encode(File::get($file));
                }
                $scenes[] = $scene;
            }
            $audio = filled($data['audio'] ?? null) ? $this->localPath(dirname($path), $data['audio']) : $brand->audioTrackPath('auto');
            $slides = collect($scenes)->map(fn (array $scene) => $images->render($brand, $scene['template'], $scene['data']));

            // A slide with a `say` is spoken; a manifest with none stays as silent as it always was. The voice is
            // asked for by the manifest, so one that cannot be made stops the render instead of going out mute.
            $narration = array_filter(array_column($scenes, 'say'), fn (mixed $say): bool => filled($say)) === []
                ? null
                : $narrator->narrateLines(
                    $brand,
                    array_map(fn (array $scene): Scene => new Scene($scene['template'], $templates->roleOf($scene['template'])), $scenes),
                    array_map(fn (array $scene): string => (string) ($scene['say'] ?? ''), $scenes),
                );

            $asset = $video->slideshow($brand, $slides, transitionSeconds: 0.0, audioPath: $audio, slideSeconds: array_column($scenes, 'seconds'), narration: $narration);
            File::copy($asset->absolutePath(), $output.'/video.mp4');
            foreach ($slides as $i => $slide) {
                File::copy($slide->absolutePath(), $output.'/slide-'.($i + 1).'.jpg');
            }
            File::put($output.'/render.json', json_encode(['duration_ms' => $asset->duration_ms, 'params' => $asset->params, 'manifest' => $path], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info('Pregled: '.$output.'/video.mp4');
            $this->line('Trajanje: '.$asset->durationSeconds().' s. Nije napravljena ni zakazana objava.');

            if ($narration !== null) {
                $this->line(sprintf('Voice-over: %d znakova, glas %s. Slajdovi su ostali onoliko koliko traje njihov redak, a ne manje od zadanog.', $narration->characters(), $narration->voiceId));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function localPath(string $directory, string $path): string
    {
        $resolved = realpath(str_starts_with($path, '/') ? $path : $directory.'/'.$path);
        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException('Datoteka medija ne postoji: '.$path);
        }

        return $resolved;
    }
}
