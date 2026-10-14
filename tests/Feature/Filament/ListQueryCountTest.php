<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\CreateDraft;
use App\Enums\DraftStatus;
use App\Filament\Resources\ContentItems\Pages\ListContentItems;
use App\Filament\Resources\PostDrafts\Pages\ListPostDrafts;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Widgets\BrandsOverview;
use App\Filament\Widgets\PendingApprovalTable;
use App\Filament\Widgets\UpcomingPostsTable;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A list that names the brand or the channels under each row has to load them with the rows. It once
 * did not (the brand column was folded into the title and its relation stopped loading itself), and
 * a page of ten rows cost seven more queries than a page of three.
 */
final class ListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));

        $this->brand = Brand::factory()->create();
        $this->source = Source::factory()->for($this->brand)->create();
    }

    public function test_no_list_asks_the_database_once_per_row(): void
    {
        $this->addRows(3);
        $this->queriesPerPage(); // the first pass warms what is cached for the rest of the request

        $few = $this->queriesPerPage();

        $this->addRows(9);
        $many = $this->queriesPerPage();

        foreach ($few as $page => $queries) {
            $this->assertSame($queries, $many[$page], "{$page}: {$queries} queries for 3 rows, {$many[$page]} for more");
        }
    }

    private function addRows(int $count): void
    {
        $accounts = SocialAccount::factory()->for($this->brand)->count(2)->create()->all();

        for ($i = 0; $i < $count; $i++) {
            $item = ContentItem::factory()->for($this->source)->for($this->brand)->create();
            $draft = app(CreateDraft::class)->execute($item, [$accounts[0]], render: false);

            // Some of each: a draft waiting, and one with a time ahead of it.
            if ($i % 2 === 0) {
                $draft->forceFill(['status' => DraftStatus::Scheduled, 'scheduled_at' => now()->addDays(1 + $i)])->save();
            }
        }

        Source::factory()->for($this->brand)->count($count)->create();
        SocialAccount::factory()->for($this->brand)->count($count)->create();
        Brand::factory()->count($count)->create();
        PostDraft::factory()->for($this->brand)->count($count)->create();
    }

    /**
     * @return array<string, int>
     */
    private function queriesPerPage(): array
    {
        $pages = [
            'drafts' => fn () => Livewire::test(ListPostDrafts::class)->set('activeTab', 'all'),
            'content items' => fn () => Livewire::test(ListContentItems::class),
            'sources' => fn () => Livewire::test(ListSources::class),
            'accounts' => fn () => Livewire::test(ListSocialAccounts::class),
            'brand overview' => fn () => Livewire::test(BrandsOverview::class),
            'pending approval' => fn () => Livewire::test(PendingApprovalTable::class),
            'upcoming' => fn () => Livewire::test(UpcomingPostsTable::class),
        ];

        $counts = [];

        foreach ($pages as $name => $render) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $render()->assertOk();
            $counts[$name] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }
}
