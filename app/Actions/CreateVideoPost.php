<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActorType;
use App\Enums\AuthType;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\SourceType;
use App\Enums\VariantStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Support\VideoProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Posts a video somebody made elsewhere — an avatar host, a recorded clip — on the brand's channels, with the words a
 * person wrote for each of them.
 *
 * Everything else in the hub starts from an item a source sent and renders its own picture. Here the video is the content:
 * it is stored like a rendered one (public disk, `media/…`, which is where TikTok's verified address prefix points), and
 * a plain item stands behind the draft so the same rules that guard every post (expiry, a link that must answer, "the
 * content changed since the draft") keep guarding this one. The item belongs to a push source of its own that nothing ever
 * pulls and no automatic rule reads, so it can never be picked for a roundup. The words are not written or checked here:
 * a person gave them, and the review screen shows them like any other draft.
 */
final class CreateVideoPost
{
    public const SOURCE_NAME = 'Gotovi videi';

    public const TEMPLATE_KEY = 'own-video';

    public const COVER_TEMPLATE_KEY = 'own-video-cover';

    public function __construct(private readonly VideoProbe $probe) {}

    /**
     * @param  array<string, array{caption?: string|null, first_comment?: string|null, link_url?: string|null, cover_timestamp_ms?: int|null, settings?: array<string, mixed>}>  $channels  platform value => what that channel posts
     * @param  string  $url  Where the post leads people (the item's own page): it is asked whether it still answers before the post goes out.
     * @param  CarbonImmutable|null  $validUntil  After this the post is skipped as expired (an offer on the screen that ran out).
     * @param  bool  $aiGenerated  Label it as AI-generated content on the networks that take the label from the hub.
     *
     * @throws InvalidArgumentException When something about the request cannot be posted; nothing is stored then.
     */
    public function execute(
        Brand $brand,
        string $videoPath,
        string $title,
        array $channels,
        string $url,
        ?string $coverPath = null,
        ?CarbonImmutable $validUntil = null,
        bool $aiGenerated = true,
        ?CarbonImmutable $scheduledAt = null,
        DraftStatus $status = DraftStatus::PendingApproval,
        ActorType $actor = ActorType::Human,
        ?int $actorId = null,
    ): PostDraft {
        $title = mb_trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('Objava treba naslov (vidi se samo u panelu).');
        }

        $targets = $this->targets($brand, $channels);
        $this->assertFile($videoPath, 'Video');

        if (! in_array(mb_strtolower(pathinfo($videoPath, PATHINFO_EXTENSION)), ['mp4', 'mov', 'm4v'], true)) {
            throw new InvalidArgumentException('Video mora biti mp4 (H.264 + AAC).');
        }

        if ($coverPath !== null) {
            $this->assertFile($coverPath, 'Naslovna slika');
        }

        try {
            $video = $this->probe->probe($videoPath);
        } catch (Throwable $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }

        if ($video['duration_ms'] === null || $video['duration_ms'] < 1000) {
            throw new InvalidArgumentException('Video je kraći od sekunde ili mu se trajanje ne može pročitati.');
        }

        $sha = (string) hash_file('sha256', $videoPath);
        $externalId = 'video-'.mb_substr($sha, 0, 32);

        $this->assertNotPostedYet($brand, $externalId);

        $disk = (string) config('hub.media_disk', 'public');
        $folder = sprintf('media/%s/%s', $brand->slug, now()->format('Y/m'));
        $stored = [];
        $coverTarget = null;
        $cover = null;

        try {
            $videoTarget = $folder.'/'.Str::uuid().'.mp4';
            $this->store($disk, $videoTarget, $videoPath);
            $stored[] = $videoTarget;

            if ($coverPath !== null) {
                $coverTarget = $folder.'/'.Str::uuid().'.jpg';
                $jpeg = tempnam(sys_get_temp_dir(), 'hub-cover-').'.jpg';

                try {
                    $this->probe->toJpeg($coverPath, $jpeg);
                    $this->store($disk, $coverTarget, $jpeg);
                    $stored[] = $coverTarget;
                    $cover = $this->probe->probe($jpeg);
                } finally {
                    @unlink($jpeg);
                }
            }

            return DB::transaction(function () use ($brand, $title, $url, $validUntil, $aiGenerated, $scheduledAt, $status, $actor, $actorId, $targets, $video, $sha, $externalId, $disk, $videoTarget, $coverTarget, $cover, $videoPath): PostDraft {
                $draft = PostDraft::query()->create([
                    'brand_id' => $brand->id,
                    'kind' => PostDraft::KIND_SINGLE,
                    'title' => mb_substr($title, 0, 300),
                    'status' => $scheduledAt !== null && $status === DraftStatus::Approved ? DraftStatus::Scheduled : $status,
                    'scheduled_at' => $scheduledAt,
                    'created_by_type' => $actor,
                    'created_by_id' => $actorId,
                ]);

                $poster = $coverTarget === null || $cover === null ? null : MediaAsset::query()->create([
                    'brand_id' => $brand->id,
                    'post_draft_id' => $draft->id,
                    'template_key' => self::COVER_TEMPLATE_KEY,
                    'params' => ['origin' => 'upload'],
                    'width' => $cover['width'],
                    'height' => $cover['height'],
                    'format' => 'jpg',
                    'disk' => $disk,
                    'path' => $coverTarget,
                    'bytes' => Storage::disk($disk)->size($coverTarget),
                    'checksum' => hash('sha256', (string) Storage::disk($disk)->get($coverTarget)),
                ]);

                $asset = MediaAsset::query()->create([
                    'brand_id' => $brand->id,
                    'post_draft_id' => $draft->id,
                    'template_key' => self::TEMPLATE_KEY,
                    'params' => ['origin' => 'upload', 'file' => basename($videoPath), 'ai_generated' => $aiGenerated],
                    'width' => $video['width'],
                    'height' => $video['height'],
                    'duration_ms' => $video['duration_ms'],
                    'poster_media_asset_id' => $poster?->id,
                    'format' => 'mp4',
                    'disk' => $disk,
                    'path' => $videoTarget,
                    'bytes' => (int) filesize($videoPath),
                    'checksum' => $sha,
                ]);

                $item = $this->item($brand, $externalId, $title, $url, $validUntil, $poster, $sha, $videoPath);
                $draft->contentItems()->attach($item->id, ['position' => 0, 'checksum' => $item->checksum, 'publication_checksum' => $item->publicationChecksum()]);

                foreach ($targets as ['account' => $account, 'channel' => $channel]) {
                    $comment = mb_trim((string) ($channel['first_comment'] ?? ''));

                    $variant = PostVariant::query()->create([
                        'post_draft_id' => $draft->id,
                        'social_account_id' => $account->id,
                        'platform' => $account->platform,
                        'caption' => mb_trim((string) $channel['caption']),
                        'link_url' => filled($channel['link_url'] ?? null) ? (string) $channel['link_url'] : $url,
                        'settings' => array_filter([
                            ...Arr::only((array) ($channel['settings'] ?? []), UpdateVariant::EDITABLE_SETTINGS),
                            'format' => ContentFormat::Video->value,
                            'ai_generated' => $aiGenerated,
                            'first_comment' => $comment === '' ? null : $comment,
                            'link_in' => $comment === '' ? null : 'comment',
                            'cover_timestamp_ms' => isset($channel['cover_timestamp_ms']) ? (int) $channel['cover_timestamp_ms'] : null,
                        ], fn (mixed $value): bool => $value !== null),
                        'status' => VariantStatus::Pending,
                    ]);

                    $variant->media()->sync([$asset->id => ['position' => 0]]);
                    $variant->markRendered();
                }

                return $draft->load(['variants.account', 'variants.media', 'contentItems']);
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk($disk)->delete($path);
            }

            throw $e;
        }
    }

    /**
     * One entry per active account of every channel asked for, each with the words of its channel.
     *
     * @param  array<string, array<string, mixed>>  $channels
     * @return list<array{account: SocialAccount, channel: array<string, mixed>}>
     */
    private function targets(Brand $brand, array $channels): array
    {
        if ($channels === []) {
            throw new InvalidArgumentException('Zadaj barem jedan kanal.');
        }

        $targets = [];

        foreach ($channels as $key => $channel) {
            $platform = Platform::tryFrom((string) $key);

            if ($platform === null) {
                throw new InvalidArgumentException("Nepoznat kanal '{$key}'.");
            }

            if (! in_array(ContentFormat::Video, $platform->formats(), true) || $platform->isManual()) {
                throw new InvalidArgumentException("{$platform->label()} ne objavljuje video iz huba.");
            }

            if (blank($channel['caption'] ?? null)) {
                throw new InvalidArgumentException("{$platform->label()}: nedostaje tekst objave.");
            }

            $accounts = SocialAccount::query()->where('brand_id', $brand->id)->where('platform', $platform->value)->active()->get();

            if ($accounts->isEmpty()) {
                throw new InvalidArgumentException("Brend {$brand->name} nema aktivan račun na kanalu {$platform->label()}.");
            }

            foreach ($accounts as $account) {
                $targets[] = ['account' => $account, 'channel' => $channel];
            }
        }

        return $targets;
    }

    private function assertFile(string $path, string $what): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("{$what} ne postoji ili se ne može pročitati: {$path}");
        }
    }

    /**
     * The same file is posted once: a second run while its post is still alive would put it on the channels twice.
     */
    private function assertNotPostedYet(Brand $brand, string $externalId): void
    {
        $existing = ContentItem::query()
            ->where('brand_id', $brand->id)->where('external_id', $externalId)
            ->first()?->postDrafts()
            ->whereNotIn('status', [DraftStatus::Discarded->value, DraftStatus::Skipped->value])
            ->first();

        if ($existing !== null) {
            throw new InvalidArgumentException("Ovaj video već ima objavu: #{$existing->id} ({$existing->status->label()}).");
        }
    }

    private function store(string $disk, string $path, string $file): void
    {
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new InvalidArgumentException("Datoteka se ne može otvoriti: {$file}");
        }

        try {
            Storage::disk($disk)->put($path, $stream, 'public');
        } finally {
            fclose($stream);
        }
    }

    private function item(Brand $brand, string $externalId, string $title, string $url, ?CarbonImmutable $validUntil, ?MediaAsset $poster, string $sha, string $videoPath): ContentItem
    {
        $source = Source::query()->firstOrCreate(
            ['brand_id' => $brand->id, 'name' => self::SOURCE_NAME],
            ['type' => SourceType::Webhook, 'auth_type' => AuthType::None, 'enabled' => true],
        );

        $now = CarbonImmutable::now();
        $images = $poster === null ? [] : [['url' => $poster->publicUrl(), 'role' => 'cover']];

        return ContentItem::query()->updateOrCreate(
            ['source_id' => $source->id, 'external_id' => $externalId],
            [
                'brand_id' => $brand->id,
                'kind' => ContentKind::Generic,
                'title' => mb_substr($title, 0, 300),
                'facts' => [],
                'badges' => [],
                'url' => $url,
                'images' => $images,
                'tags' => [],
                'raw' => ['own_video' => ['file' => basename($videoPath), 'sha256' => $sha]],
                'published_at' => $now,
                'expires_at' => $validUntil,
                'source_updated_at' => $now,
                'checksum' => hash('sha256', json_encode([$externalId, $title, $url, $validUntil?->toIso8601String()], JSON_THROW_ON_ERROR)),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );
    }
}
