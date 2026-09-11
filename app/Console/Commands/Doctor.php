<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rendering\ImageRenderer;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class Doctor extends Command
{
    protected $signature = 'hub:doctor {--skip-render : Do not launch Chromium}';

    protected $description = 'Check everything publishing depends on: database, queue, storage link, public media URL, Chromium, fonts, Meta config';

    public function handle(ImageRenderer $renderer): int
    {
        $rows = [];
        $ok = true;

        $rows[] = $this->check('Database', function (): string {
            DB::select('select 1');

            return DB::connection()->getDriverName().' ok';
        }, $ok);

        $rows[] = $this->check('Queue connection', fn (): string => (string) config('queue.default'), $ok);

        $rows[] = $this->check('Media disk writable', function (): string {
            $disk = (string) config('hub.media_disk');
            Storage::disk($disk)->put('media/.doctor', 'ok');
            Storage::disk($disk)->delete('media/.doctor');

            return $disk;
        }, $ok);

        $rows[] = $this->check('public/storage symlink', function (): string {
            $link = public_path('storage');

            return is_link($link) || is_dir($link) ? 'present' : throw new RuntimeException('missing — run php artisan storage:link');
        }, $ok);

        $rows[] = $this->check('Public media URL', function (): string {
            $disk = (string) config('hub.media_disk');
            Storage::disk($disk)->put('media/doctor.txt', 'ok', 'public');
            $url = Storage::disk($disk)->url('media/doctor.txt');
            if (! str_starts_with($url, 'http')) {
                $url = mb_rtrim((string) config('app.url'), '/').'/'.mb_ltrim($url, '/');
            }

            try {
                $status = Http::timeout(10)->get($url)->status();
            } catch (ConnectionException $e) {
                throw new RuntimeException("{$url} unreachable: {$e->getMessage()} (fine on local Sail; Meta needs this in production)");
            } finally {
                Storage::disk($disk)->delete('media/doctor.txt');
            }

            return $status === 200 ? "{$url} → 200" : throw new RuntimeException("{$url} → {$status}");
        }, $ok, fatal: false);

        $rows[] = $this->check('Node binary', function (): string {
            $node = (string) (config('hub.render.node_binary') ?: 'node');
            $process = Process::fromShellCommandline("{$node} --version");
            $process->run();

            return $process->isSuccessful() ? mb_trim($process->getOutput()) : throw new RuntimeException($process->getErrorOutput() ?: 'not found');
        }, $ok);

        $rows[] = $this->check('puppeteer module', function (): string {
            $path = base_path('node_modules/puppeteer/package.json');

            return is_file($path) ? 'v'.(json_decode((string) file_get_contents($path), true)['version'] ?? '?') : throw new RuntimeException('npm install puppeteer');
        }, $ok);

        $rows[] = $this->check('ffmpeg', function (): string {
            $process = Process::fromShellCommandline('ffmpeg -version 2>&1 | head -1');
            $process->run();

            return $process->isSuccessful() && str_contains($process->getOutput(), 'ffmpeg version')
                ? mb_trim($process->getOutput())
                : throw new RuntimeException('nije instaliran — hub:render-video i Reels/TikTok objave neće raditi');
        }, $ok, fatal: false);

        $rows[] = $this->check('Emoji font', function (): string {
            $process = Process::fromShellCommandline('fc-list 2>/dev/null | grep -i -c "emoji"');
            $process->run();

            return (int) mb_trim($process->getOutput()) > 0 ? 'installed' : throw new RuntimeException('no emoji font found (fonts-noto-color-emoji)');
        }, $ok, fatal: false);

        if (! $this->option('skip-render')) {
            $rows[] = $this->check('Chromium render', function () use ($renderer): string {
                $started = microtime(true);
                $png = $renderer->browsershot('<html><body style="background:#fff"><h1>hub 🚀 čšž</h1></body></html>', 300, 200)->screenshot();

                return mb_strlen($png) > 1000 ? sprintf('%d bytes in %.1fs', mb_strlen($png), microtime(true) - $started) : throw new RuntimeException('empty screenshot');
            }, $ok);
        }

        $rows[] = $this->check('TikTok config', function (): string {
            foreach (['client_key', 'client_secret'] as $key) {
                if (blank(config("tiktok.{$key}"))) {
                    throw new RuntimeException('TIKTOK_'.mb_strtoupper($key).' not set (TikTok objave isključene)');
                }
            }

            return 'ključ i tajna postavljeni';
        }, $ok, fatal: false);

        $rows[] = $this->check('Meta app config', function (): string {
            foreach (['app_id', 'app_secret'] as $key) {
                if (blank(config("meta.{$key}"))) {
                    throw new RuntimeException('META_'.mb_strtoupper($key).' not set');
                }
            }

            return 'app id + secret present, '.config('meta.graph_version');
        }, $ok, fatal: false);

        $rows[] = $this->check('AI (Anthropic) config', function (): string {
            if (blank(config('hub.ai.api_key'))) {
                throw new RuntimeException('ANTHROPIC_API_KEY not set (hub:agent-draft ne može pisati objave)');
            }

            return config('hub.ai.model').', effort='.config('hub.ai.effort');
        }, $ok, fatal: false);

        $rows[] = $this->check('Admin e-mails', function (): string {
            $emails = (array) config('hub.admin_emails');

            return $emails !== [] ? implode(', ', $emails) : throw new RuntimeException('HUB_ADMIN_EMAILS empty — nobody can log in');
        }, $ok, fatal: false);

        $this->table(['Check', 'Status', 'Detail'], $rows);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  callable(): string  $probe
     * @return array{0: string, 1: string, 2: string}
     */
    private function check(string $name, callable $probe, bool &$ok, bool $fatal = true): array
    {
        try {
            return [$name, '<info>OK</info>', $probe()];
        } catch (Throwable $e) {
            if ($fatal) {
                $ok = false;
            }

            return [$name, $fatal ? '<error>FAIL</error>' : '<comment>WARN</comment>', $e->getMessage()];
        }
    }
}
