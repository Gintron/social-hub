<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * YouTube rules for brands whose channel has just been connected.
 *
 * A connected account posts nothing by itself: the hub posts to the platforms a source has an enabled rule for
 * (ApplyAutoPublishRules, AutoLineup, ScheduleDigest). This adds the missing YouTube rule to every enabled source of
 * the given brands that already posts automatically somewhere, modelled on the source's TikTok rule (the other
 * channel that only takes video) or, without one, on its first enabled rule: same delay and daily cap, same voice-over
 * choice. The rule starts asking for approval, because the first live upload is still to be seen; `--no-approval` skips that.
 */
final class YouTubeRules extends Command
{
    protected $signature = 'hub:youtube-rules
        {brands* : Slugs of the brands (e.g. uselisto uselisto-at)}
        {--no-approval : Let the rule post without a person\'s look}
        {--dry-run : Show what would be made and make nothing}';

    protected $description = 'Add the YouTube rule to the automatic sources of brands whose channel is connected';

    public function handle(): int
    {
        $noApproval = (bool) $this->option('no-approval');
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ((array) $this->argument('brands') as $slug) {
            $brand = Brand::query()->where('slug', (string) $slug)->first();

            if ($brand === null) {
                $this->error("No brand '{$slug}'.");

                return self::FAILURE;
            }

            if (! $brand->socialAccounts()->where('platform', Platform::YouTube->value)->where('status', AccountStatus::Active->value)->exists()) {
                $this->warn("⚠ '{$brand->slug}' has no active YouTube account; rules would wait for one.");
            }

            $sources = $brand->sources()->enabled()->with('autoPublishRules')->orderBy('id')->get();

            foreach ($sources as $source) {
                $rows[] = [$brand->slug, $source->name, $this->add($source, $noApproval, $dryRun)];
            }
        }

        $this->table(['brand', 'source', 'result'], $rows);
        $this->comment($dryRun ? 'Dry run: nothing was made.' : 'Rules are in the panel (source → Automatska objava).');

        return self::SUCCESS;
    }

    private function add(Source $source, bool $noApproval, bool $dryRun): string
    {
        $rules = $source->autoPublishRules;

        if ($rules->contains(fn (AutoPublishRule $rule): bool => $rule->platform === Platform::YouTube)) {
            return 'already has one';
        }

        $enabled = $rules->filter(fn (AutoPublishRule $rule): bool => $rule->enabled && ! $rule->platform->isManual());
        $model = $enabled->first(fn (AutoPublishRule $rule): bool => $rule->platform === Platform::TikTok) ?? $enabled->first();

        if ($model === null) {
            return 'skipped: posts nowhere automatically';
        }

        if (! $dryRun) {
            $voiceover = $model->channelSettings()['voiceover'] ?? null;

            $source->autoPublishRules()->create([
                'platform' => Platform::YouTube,
                'enabled' => true,
                'requires_approval' => ! $noApproval,
                'delay_minutes' => $model->delay_minutes,
                'daily_cap' => $model->daily_cap,
                'format' => ContentFormat::Video,
                'settings' => $voiceover === null ? null : ['voiceover' => $voiceover],
            ]);
        }

        return 'rule from '.$model->platform->value.($noApproval ? '' : ', asks for approval');
    }
}
