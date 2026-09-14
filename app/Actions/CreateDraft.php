<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActorType;
use App\Enums\ContentFormat;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Rendering\TemplateRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns one content item into a draft with a variant per target account and a rendered image.
 * Used identically by the Filament UI, the MCP tools and the drafting agent.
 */
final class CreateDraft
{
    public function __construct(
        private readonly \App\Drafting\CaptionBuilder $captions,
        private readonly TemplateRegistry $templates,
        private readonly PrepareVariantMedia $media,
    ) {}

    /**
     * @param  Collection<int, SocialAccount>|list<SocialAccount>  $accounts
     * @param  array<string, string>  $captionOverrides  platform value => caption
     * @param  array<string, mixed>  $templateOverrides
     * @param  array<string, array{format?: ContentFormat|string, settings?: array<string, mixed>}>  $channelOptions  platform value => how that channel posts
     */
    public function execute(
        ContentItem $item,
        iterable $accounts,
        ActorType $actor = ActorType::Human,
        ?int $actorId = null,
        ?string $templateKey = null,
        array $captionOverrides = [],
        array $templateOverrides = [],
        ?CarbonImmutable $scheduledAt = null,
        DraftStatus $status = DraftStatus::PendingApproval,
        ?int $agentRunId = null,
        bool $render = true,
        array $channelOptions = [],
    ): PostDraft {
        $accounts = collect($accounts);

        if ($accounts->isEmpty()) {
            throw new InvalidArgumentException('A draft needs at least one target account.');
        }

        $brand = $item->brand;
        $settings = [];

        foreach ($accounts as $account) {
            if ($account->brand_id !== $brand->id) {
                throw new InvalidArgumentException("Account {$account->name} belongs to another brand than the content item.");
            }

            $settings[$account->id] = $this->settingsFor($account->platform, $channelOptions[$account->platform->value] ?? []);
        }

        // No template means one per channel: portrait for Facebook and Instagram, vertical for TikTok.
        if ($templateKey !== null) {
            $this->templates->get($templateKey);
        }

        $draft = DB::transaction(function () use ($item, $accounts, $actor, $actorId, $captionOverrides, $scheduledAt, $status, $agentRunId, $brand, $settings): PostDraft {
            $draft = PostDraft::query()->create([
                'brand_id' => $brand->id,
                'kind' => PostDraft::KIND_SINGLE,
                'title' => mb_substr($item->title, 0, 300),
                'status' => $scheduledAt !== null && $status === DraftStatus::Approved ? DraftStatus::Scheduled : $status,
                'scheduled_at' => $scheduledAt,
                'created_by_type' => $actor,
                'created_by_id' => $actorId,
                'agent_run_id' => $agentRunId,
            ]);

            $draft->contentItems()->attach($item->id, ['position' => 0, 'checksum' => $item->checksum]);

            foreach ($accounts as $account) {
                PostVariant::query()->create([
                    'post_draft_id' => $draft->id,
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'caption' => $captionOverrides[$account->platform->value] ?? $this->captions->for($account->platform, $item, $brand),
                    'link_url' => $item->url,
                    'settings' => $settings[$account->id],
                    'status' => VariantStatus::Pending,
                ]);
            }

            return $draft;
        });

        if ($render) {
            $this->media->execute($draft->variants()->get(), templateKey: $templateKey, overrides: $templateOverrides);
        }

        return $draft->load(['variants.account', 'contentItems']);
    }

    /**
     * A channel's starting settings: the requested format (or the platform's default) plus the
     * settings a human could also set on the review screen, and nothing else.
     *
     * @param  array{format?: ContentFormat|string, settings?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    private function settingsFor(Platform $platform, array $options): array
    {
        $format = $options['format'] ?? $platform->defaultFormat();
        $format = $format instanceof ContentFormat ? $format : ContentFormat::from((string) $format);

        if (! in_array($format, $platform->formats(), true)) {
            throw new InvalidArgumentException("{$platform->label()} ne podržava format {$format->label()}.");
        }

        return [
            ...Arr::only((array) ($options['settings'] ?? []), UpdateVariant::EDITABLE_SETTINGS),
            'format' => $format->value,
        ];
    }
}
