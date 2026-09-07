<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApproveDraft;
use App\Mcp\Tools\CreateDraft;
use App\Mcp\Tools\GetCandidate;
use App\Mcp\Tools\ListCandidates;
use App\Mcp\Tools\ListDrafts;
use App\Mcp\Tools\ListSources;
use App\Mcp\Tools\MarkManualPosted;
use App\Mcp\Tools\PublishDraft;
use App\Mcp\Tools\PublishStats;
use App\Mcp\Tools\RenderPreview;
use App\Mcp\Tools\UpdateVariant;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Social Hub')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
Social Hub turns content from several websites into posts on Facebook Pages, Instagram and (by hand)
Facebook groups. Everything is per brand: studentski-poslovi.hr, radim.hr, uselisto.com.

Start with hub.list_sources to learn the brand slugs, then hub.list_candidates to see what could be
posted. hub.get_candidate returns the full item; read it before writing anything.

Writing posts:
- State only what the candidate says. Never invent a price, a wage, a discount, a date, a location
  or a link — the hub rejects captions containing amounts or URLs that are not in the item's data.
- Instagram captions carry no clickable links; point at the link in the profile instead.
  Limits: Instagram 2200 characters and 30 hashtags, Facebook 63000 characters.
- Write in Croatian unless the brand's voice says otherwise.

What is safe to do on your own and what is not:
- Reading and drafting are safe. hub.create_draft leaves the post waiting for a human.
- hub.approve_draft and hub.publish_draft make a post public. Do not call them unless the person you
  are working for asked for that specific post to go out.
- hub.mark_manual_posted only records that someone pasted a group post by hand. Never call it to
  "finish" a task; call it after a human confirms they posted.

Tokens are scoped: a drafting token can read and draft but not approve or publish, and the tool will
say so rather than doing half the work.
TEXT)]
final class SocialHubServer extends Server
{
    protected array $tools = [
        ListSources::class,
        ListCandidates::class,
        GetCandidate::class,
        CreateDraft::class,
        RenderPreview::class,
        UpdateVariant::class,
        ListDrafts::class,
        ApproveDraft::class,
        PublishDraft::class,
        MarkManualPosted::class,
        PublishStats::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
