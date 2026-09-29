<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Enums\ContentFormat;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Publishing\FormatCheck;

/**
 * The shapes MCP tools return. One place, so "a draft" looks the same in every tool's output.
 */
final class Present
{
    /**
     * @return array<string, mixed>
     */
    public static function item(ContentItem $item, bool $full = false): array
    {
        $base = [
            'id' => $item->id,
            'external_id' => $item->external_id,
            'brand' => $item->brand?->slug,
            'kind' => $item->kind->value,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'priority' => $item->priority,
            'url' => $item->url,
            'expires_at' => $item->expires_at?->toIso8601ZuluString(),
            'has_image' => $item->imageUrl('primary') !== null,
            'drafted' => $item->postDrafts()->exists(),
        ];

        if (! $full) {
            return $base;
        }

        return [
            ...$base,
            'body_text' => $item->body_text,
            'facts' => $item->facts,
            'badges' => $item->badges,
            'price' => $item->price,
            'cta' => $item->cta,
            'images' => $item->images,
            'tags' => $item->tags,
            'published_at' => $item->published_at?->toIso8601ZuluString(),
            'source' => $item->source?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function draft(PostDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'brand' => $draft->brand?->slug,
            'kind' => $draft->kind,
            'title' => $draft->title,
            'status' => $draft->status->value,
            'scheduled_at' => $draft->scheduled_at?->toIso8601ZuluString(),
            'created_by' => $draft->created_by_type->value,
            'stale_source' => $draft->staleItems()->isNotEmpty(),
            'items' => $draft->contentItems->map(fn (ContentItem $item): array => self::item($item))->all(),
            'variants' => $draft->variants->map(fn (PostVariant $variant): array => self::variant($variant))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function variant(PostVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'platform' => $variant->platform->value,
            'account' => $variant->account?->name,
            'status' => $variant->status->value,
            'format' => $variant->format()->value,
            'media_problem' => app(FormatCheck::class)->problem($variant),
            'caption' => $variant->caption,
            'caption_chars' => mb_strlen($variant->caption),
            'media' => $variant->media->map(fn ($asset): array => [
                'url' => $asset->publicUrl(),
                'template' => $asset->template_key,
                'size' => $asset->width.'x'.$asset->height,
            ])->all(),
            'voiceover' => self::voiceover($variant),
            'permalink' => $variant->permalink,
            'published_at' => $variant->published_at?->toIso8601ZuluString(),
            'error' => $variant->error_message,
        ];
    }

    /**
     * Whether a video channel is narrated and what its video says; null for anything that has no sound.
     *
     * @return array{wanted: bool, status: string, script?: list<string>, error?: string}|null
     */
    private static function voiceover(PostVariant $variant): ?array
    {
        if ($variant->format() !== ContentFormat::Video) {
            return null;
        }

        $params = (array) data_get($variant->media->first(fn ($asset): bool => $asset->isVideo()), 'params.voiceover', []);

        return array_filter([
            'wanted' => $variant->wantsVoiceover(),
            'status' => ($params['status'] ?? null) === 'ok' ? 'spoken' : (($params['status'] ?? null) === 'failed' ? 'failed' : 'none'),
            'script' => ($params['status'] ?? null) === 'ok' ? array_column((array) ($params['script'] ?? []), 'text') : null,
            'error' => ($params['status'] ?? null) === 'failed' ? (string) ($params['error'] ?? '') : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
