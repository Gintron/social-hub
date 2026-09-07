<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\AgentDrafter;
use App\Enums\ContentKind;
use App\Models\Brand;
use Illuminate\Console\Command;
use Throwable;

final class AgentDraft extends Command
{
    protected $signature = 'hub:agent-draft
        {brand? : Brand slug; omit to run every brand that has the agent enabled}
        {--kind= : Restrict to one content kind}
        {--limit=5 : How many candidates to draft per brand}
        {--dry-run : Write nothing; print what the model produced}';

    protected $description = 'Have Claude write captions for the newest candidates and leave them waiting for approval';

    public function handle(AgentDrafter $drafter): int
    {
        if (blank(config('hub.ai.api_key'))) {
            $this->error('ANTHROPIC_API_KEY nije postavljen; agent ne može pisati objave.');

            return self::FAILURE;
        }

        $kind = null;

        if (filled($this->option('kind'))) {
            $kind = ContentKind::tryFrom((string) $this->option('kind'));

            if ($kind === null) {
                $this->error("Unknown kind '{$this->option('kind')}'.");

                return self::FAILURE;
            }
        }

        $brands = $this->brands();

        if ($brands->isEmpty()) {
            $this->warn('Nijedan brend nema uključenog agenta (Brend → Glas brenda → AI piše nacrte).');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($brands as $brand) {
            try {
                $result = $drafter->run($brand, $kind, (int) $this->option('limit'), (bool) $this->option('dry-run'));
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$brand->name}: {$e->getMessage()}");

                continue;
            }

            $this->line(sprintf(
                '<info>%s</info>: %d nacrt(a)%s%s',
                $brand->name,
                $result['drafted'],
                $result['fallbacks'] > 0 ? ", {$result['fallbacks']} s rezervnim tekstom" : '',
                $result['failed'] > 0 ? ", {$result['failed']} neuspjelo" : '',
            ));

            foreach ($result['titles'] as $title) {
                $this->line('  • '.$title);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Brand>
     */
    private function brands(): \Illuminate\Support\Collection
    {
        if (filled($this->argument('brand'))) {
            $brand = Brand::query()->where('slug', (string) $this->argument('brand'))->first();

            if ($brand === null) {
                $this->error("No brand '{$this->argument('brand')}'.");

                return collect();
            }

            return collect([$brand]);
        }

        return Brand::query()->get()->filter(fn (Brand $brand): bool => (bool) data_get($brand->voice, 'agent_enabled', false))->values();
    }
}
