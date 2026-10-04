<?php

declare(strict_types=1);

namespace App\Rendering\CatalogVideo;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Has Chromium draw the frames of a scene bundle (resources/js/catalog-frames.mjs): one JPEG per frame, or a few
 * PNG stills to look at. The scene is a page that draws itself for a given time, so the same bundle always gives the same
 * frames, and a wrong one is found by asking for that second.
 */
final class CatalogFrameRenderer
{
    /**
     * @return array{frames: int, unique: int, seconds: float, duration: float}
     */
    public function frames(string $bundle, string $framesDir): array
    {
        /** @var array{frames: int, unique: int, seconds: float, duration: float} */
        return $this->run([
            $bundle, '--out', $framesDir, '--quality', (string) (int) config('catalog_video.jpeg_quality', 94),
        ]);
    }

    /**
     * @param  list<float>  $times
     * @return list<string> Paths of the PNG stills, in the order of $times.
     */
    public function stills(string $bundle, string $outDir, array $times): array
    {
        $this->run([$bundle, '--out', $outDir, '--at', implode(',', array_map(fn (float $t): string => (string) $t, $times))]);

        return array_map(fn (float $t): string => sprintf('%s/still-%.2f.png', $outDir, $t), $times);
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function run(array $arguments): array
    {
        $process = new Process(
            [config('hub.render.node_binary') ?: 'node', resource_path('js/catalog-frames.mjs'), ...$arguments],
            base_path(),
            array_filter(['HUB_CHROME_PATH' => config('hub.render.chrome_path') ?: null]),
            timeout: (int) config('catalog_video.frames_timeout', 420),
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Snimanje sličica nije uspjelo: '.mb_substr(mb_trim($process->getErrorOutput() ?: $process->getOutput()), 0, 600));
        }

        $lines = array_values(array_filter(explode("\n", mb_trim($process->getOutput()))));
        $summary = json_decode((string) end($lines), true);

        if (! is_array($summary)) {
            throw new RuntimeException('Snimanje sličica nije vratilo ishod: '.mb_substr($process->getOutput(), 0, 300));
        }

        return $summary;
    }
}
