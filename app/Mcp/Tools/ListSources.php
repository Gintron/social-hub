<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\Ability;
use App\Models\Brand;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('hub.list_sources')]
#[Description('List the brands the hub publishes for, their content sources and their connected channels. Start here: every other tool needs a brand slug or an id from this list.')]
final class ListSources extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        Ability::require($request, 'mcp');

        $brands = Brand::query()->with(['sources', 'socialAccounts'])->orderBy('name')->get();

        return Response::structured([
            'brands' => $brands->map(fn (Brand $brand): array => [
                'slug' => $brand->slug,
                'name' => $brand->name,
                'site_url' => $brand->site_url,
                'timezone' => $brand->timezone,
                'agent_enabled' => (bool) data_get($brand->voice, 'agent_enabled', false),
                'sources' => $brand->sources->map(fn ($source): array => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'type' => $source->type->value,
                    'enabled' => $source->enabled,
                    'last_synced_at' => $source->last_synced_at?->toIso8601ZuluString(),
                    'last_error' => $source->last_error,
                ])->all(),
                'accounts' => $brand->socialAccounts->map(fn ($account): array => [
                    'id' => $account->id,
                    'platform' => $account->platform->value,
                    'name' => $account->name,
                    'status' => $account->status->value,
                    'manual' => $account->platform->isManual(),
                ])->all(),
            ])->all(),
        ]);
    }
}
