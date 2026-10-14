<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Tests\TestCase;

/**
 * Resource pages give a form two columns unless it says otherwise, so sections of different heights
 * sit side by side with gaps under the shorter one. That is the layout this panel was reported for;
 * every form and infolist says "one column" out loud, and long ones are tabs (CLAUDE.md, "Panel (izgled)").
 */
final class PanelLayoutTest extends TestCase
{
    public function test_every_form_and_infolist_of_the_panel_is_one_column(): void
    {
        $checked = 0;

        /** @var class-string<resource> $resource */
        foreach (filament()->getDefaultPanel()->getResources() as $resource) {
            foreach (['form' => $resource::form(Schema::make()), 'infolist' => $resource::infolist(Schema::make())] as $kind => $schema) {
                // A resource with no form or infolist of its own hands back an empty schema.
                if ($schema->getComponents(withHidden: true) === []) {
                    continue;
                }

                $checked++;

                $this->assertTrue($schema->hasCustomColumns(), "{$resource}::{$kind}() leaves its columns to the page, which uses two");
                $this->assertSame(1, $schema->getColumns()['lg'] ?? null, "{$resource}::{$kind}() is not one column");
            }
        }

        // Brands, sources, accounts, drafts (forms), and the content item's infolist.
        $this->assertGreaterThanOrEqual(5, $checked);
    }

    public function test_create_and_edit_pages_stop_at_a_readable_width(): void
    {
        foreach (['Brands\\Pages\\CreateBrand', 'Brands\\Pages\\EditBrand', 'Sources\\Pages\\CreateSource', 'Sources\\Pages\\EditSource', 'SocialAccounts\\Pages\\CreateSocialAccount', 'SocialAccounts\\Pages\\EditSocialAccount'] as $page) {
            $class = "App\\Filament\\Resources\\{$page}";

            $this->assertSame(\Filament\Support\Enums\Width::FiveExtraLarge, (new $class)->getMaxContentWidth(), "{$class} is as wide as a table");
        }
    }
}
