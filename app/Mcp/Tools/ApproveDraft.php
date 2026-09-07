<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ApproveDraft as ApproveDraftAction;
use App\Actions\ScheduleDraft;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\PostDraft;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('hub.approve_draft')]
#[Description('Approve a post, optionally scheduling it. An approved post is published by the hub when its time comes, so treat this as sending it out. Requires a token with the approve ability.')]
final class ApproveDraft extends Tool
{
    public function handle(Request $request, ApproveDraftAction $approve, ScheduleDraft $schedule): ResponseFactory
    {
        Ability::require($request, 'mcp:approve');

        $validated = $request->validate([
            'draft_id' => ['required', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $draft = PostDraft::query()->with(['brand', 'variants'])->find($validated['draft_id']);

        if ($draft === null) {
            return Response::make(Response::error("Nema nacrta #{$validated['draft_id']}."));
        }

        try {
            $at = filled($validated['scheduled_at'] ?? null) ? CarbonImmutable::parse((string) $validated['scheduled_at'])->utc() : null;

            $draft = $at !== null
                ? $schedule->execute($draft, $at, Ability::userId($request))
                : $approve->execute($draft, Ability::userId($request));
        } catch (Throwable $e) {
            return Response::make(Response::error($e->getMessage()));
        }

        return Response::structured([
            'approved' => true,
            'draft' => Present::draft($draft->fresh(['brand', 'contentItems', 'variants.account', 'variants.media'])),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->description('Draft id from hub.list_drafts.')->required(),
            'scheduled_at' => $schema->string()->description('ISO 8601 time to publish at. Omit to approve without scheduling.'),
        ];
    }
}
