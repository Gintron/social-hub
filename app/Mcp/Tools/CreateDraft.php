<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateDraft as CreateDraftAction;
use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Rendering\TemplateRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('hub.create_draft')]
#[Description('Turn one candidate into a post waiting for approval, with your own caption per channel. Only states facts present on the candidate: never invent prices, pay, dates or links. The image renders in the background. This does not publish anything.')]
final class CreateDraft extends Tool
{
    public function handle(Request $request, CreateDraftAction $action, TemplateRegistry $templates): ResponseFactory
    {
        Ability::require($request, 'mcp:draft');

        $validated = $request->validate([
            'content_item_id' => ['required', 'integer'],
            'facebook_caption' => ['nullable', 'string'],
            'instagram_caption' => ['nullable', 'string'],
            'template' => ['nullable', 'string'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string'],
        ]);

        $item = ContentItem::query()->with('brand')->find($validated['content_item_id']);

        if ($item === null) {
            return Response::make(Response::error("Nema stavke #{$validated['content_item_id']}."));
        }

        $accounts = SocialAccount::query()
            ->where('brand_id', $item->brand_id)
            ->active()
            ->when(filled($validated['platforms'] ?? null), fn ($query) => $query->whereIn('platform', $validated['platforms']))
            ->get();

        if ($accounts->isEmpty()) {
            return Response::make(Response::error("Brend {$item->brand?->name} nema aktivan kanal za tražene platforme."));
        }

        $captions = array_filter([
            'fb_page' => $validated['facebook_caption'] ?? null,
            'fb_group' => $validated['facebook_caption'] ?? null,
            'ig_business' => $validated['instagram_caption'] ?? null,
        ], fn (?string $caption): bool => filled($caption));

        try {
            $draft = $action->execute(
                item: $item,
                accounts: $accounts,
                actor: ActorType::Agent,
                actorId: Ability::userId($request),
                templateKey: filled($validated['template'] ?? null) ? $validated['template'] : null,
                captionOverrides: $captions,
                status: DraftStatus::PendingApproval,
            );
        } catch (Throwable $e) {
            return Response::make(Response::error($e->getMessage()));
        }

        return Response::structured([
            'created' => true,
            'note' => 'Nacrt čeka odobrenje. Slika se renderira u pozadini; pozovi hub.list_drafts za par sekundi da vidiš gotove slike.',
            'draft' => Present::draft($draft->load(['brand', 'contentItems', 'variants.account', 'variants.media'])),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content_item_id' => $schema->integer()->description('Candidate id from hub.list_candidates.')->required(),
            'facebook_caption' => $schema->string()->description('Facebook text. Leave out to use the hub\'s deterministic caption.'),
            'instagram_caption' => $schema->string()->description('Instagram text, max 2200 characters, no links (they are not clickable there).'),
            'template' => $schema->string()->description('Image template key, e.g. kinds/job-square. Defaults to the one matching the kind.'),
            'platforms' => $schema->array()->description('Restrict to these platforms: fb_page, ig_business, fb_group.'),
        ];
    }
}
