<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ApplyAutoPublishRules;
use App\Enums\SourceType;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Source;
use App\Sources\ContentItemData;
use App\Sources\FeedValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push instead of pull: a site that cannot expose a feed sends its items here as they change.
 *
 * The body is the same Social Feed v1 payload the pull endpoint returns, so a site can switch
 * between the two without changing what it produces. Authentication is an HMAC-SHA256 signature of
 * the raw body, because there is no session and a bearer token in a webhook is a token in a log.
 */
final class IngestController extends Controller
{
    public function __construct(
        private readonly FeedValidator $validator,
        private readonly ApplyAutoPublishRules $autoPublish,
    ) {}

    public function __invoke(Request $request, string $source): JsonResponse
    {
        $model = Source::query()->with('brand')->where('name', $source)->where('type', SourceType::Webhook->value)->first();

        if ($model === null || ! $model->enabled) {
            // Same answer for "no such source" and "source is off": a probe learns nothing either way.
            return response()->json(['error' => 'not_found'], 404);
        }

        if (! $this->signatureValid($request, (string) $model->secret)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();
        $errors = $this->validator->errors($payload);

        if ($errors !== []) {
            return response()->json(['error' => 'invalid_payload', 'details' => array_slice($errors, 0, 10)], 422);
        }

        $seenAt = CarbonImmutable::now();
        $created = 0;
        $updated = 0;

        foreach ($payload['items'] as $raw) {
            try {
                $data = ContentItemData::fromFeedItem($raw);
            } catch (Throwable $e) {
                Log::warning('hub.ingest.bad_item', ['source' => $model->name, 'error' => $e->getMessage()]);

                continue;
            }

            $this->upsert($model, $data, $seenAt, $created, $updated);
        }

        $model->forceFill(['last_synced_at' => $seenAt, 'last_error' => null])->save();

        if ($created > 0) {
            $this->autoPublish->execute($model);
        }

        return response()->json(['status' => 'ok', 'created' => $created, 'updated' => $updated]);
    }

    private function upsert(Source $source, ContentItemData $data, CarbonImmutable $seenAt, int &$created, int &$updated): void
    {
        DB::transaction(function () use ($source, $data, $seenAt, &$created, &$updated): void {
            $existing = ContentItem::query()
                ->where('source_id', $source->id)
                ->where('external_id', $data->externalId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                ContentItem::query()->create([
                    ...$data->toAttributes(),
                    'source_id' => $source->id,
                    'brand_id' => $source->brand_id,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                ]);
                $created++;

                return;
            }

            if ($existing->checksum !== $data->checksum()) {
                // Seen again: the source vouches for the item, so its page gets checked afresh.
                $existing->fill([...$data->toAttributes(), 'last_seen_at' => $seenAt])->forceFill(['link_dead_at' => null])->save();
                $updated++;

                return;
            }

            $existing->forceFill(['last_seen_at' => $seenAt, 'link_dead_at' => null])->saveQuietly();
        });
    }

    /**
     * HMAC-SHA256 of the exact bytes received, compared in constant time.
     */
    private function signatureValid(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $provided = (string) $request->header('X-Signature', '');

        if ($provided === '') {
            return false;
        }

        // Both "sha256=<hex>" (GitHub style) and a bare hex digest are accepted.
        $provided = str_starts_with($provided, 'sha256=') ? mb_substr($provided, 7) : $provided;
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
