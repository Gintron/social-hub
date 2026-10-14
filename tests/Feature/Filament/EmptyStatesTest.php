<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\ContentItems\Pages\ListContentItems;
use App\Filament\Resources\PostDrafts\Pages\ListPostDrafts;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A new person's first sight of each list is an empty one: it says what the list is and what to do next.
 */
final class EmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function lists(): array
    {
        return [
            'brands' => [ListBrands::class, 'Još nema brendova', 'Dodaj brend'],
            'sources' => [ListSources::class, 'Još nema izvora sadržaja', 'Dodaj izvor'],
            'accounts' => [ListSocialAccounts::class, 'Još nema povezanih računa', 'gumbima iznad tablice'],
            'content' => [ListContentItems::class, 'Nema stavki za ovaj prikaz', 'Izvori sadržaja'],
        ];
    }

    #[DataProvider('lists')]
    public function test_an_empty_list_says_what_to_do_next(string $page, string $heading, string $next): void
    {
        Livewire::test($page)->assertOk()->assertSee($heading)->assertSee($next);
    }

    public function test_the_drafts_say_something_different_in_each_tab(): void
    {
        $headings = [
            'pending' => 'Ništa ne čeka odobrenje',
            'scheduled' => 'Ništa nije zakazano',
            'published' => 'Još ništa nije objavljeno',
            'failed' => 'Nema neuspjelih objava',
            'all' => 'Još nema objava',
        ];

        foreach ($headings as $tab => $heading) {
            Livewire::test(ListPostDrafts::class)->set('activeTab', $tab)->assertSee($heading);
        }

        Livewire::test(ListPostDrafts::class)->assertSee('Sadržaj iz izvora');
    }
}
