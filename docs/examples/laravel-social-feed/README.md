# Referentna implementacija Social Feed v1 za Laravel stranicu

Kopija koda iz `studentski-poslovi` (oglasi za posao). Za novu stranicu: kopiraj datoteke, prilagodi
`JobSocialData::fromJob()` (ili napiši svoj DTO za drugu vrstu sadržaja) i `BRAND` u kontroleru.
Ugovor: [`../../social-feed-v1.md`](../../social-feed-v1.md).

| Datoteka | Kamo | Uloga |
|---|---|---|
| `JobSocialData.php` | `app/Services/SocialMedia/` | Domenski model → normalizirana polja (naslov, činjenice, oznake, cijena, slike…). |
| `SocialFeedItemResource.php` | `app/Http/Resources/` | DTO → jedna Social Feed v1 stavka. |
| `SocialFeedController.php` | `app/Http/Controllers/Api/` | `GET /api/social-feed` sa `since`, `cursor`, `limit`; vraća `version`, `brand`, `items`, `next_cursor`. |
| `IssueSocialHubToken.php` | `app/Console/Commands/` | `php artisan social:issue-hub-token admin@example.com` → Sanctum token s ability `social:read`. |
| `SocialFeedTest.php` | `tests/Feature/Api/` | 401/403, samo aktivne plaćene stavke, `since`, oblik stavke, kursor, limit. |

## Ruta (`routes/api.php`)

```php
use App\Http\Controllers\Api\SocialFeedController;

Route::middleware(['auth:sanctum', 'abilities:social:read'])
    ->get('/social-feed', SocialFeedController::class)
    ->name('api.social-feed');
```

## Sanctum aliasi

Laravel 11+ (`bootstrap/app.php`):

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
    ]);
})
```

Laravel 10 struktura (`app/Http/Kernel.php`, `$middlewareAliases`):

```php
'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
```

## Preduvjeti

- `laravel/sanctum` instaliran, `User` koristi `HasApiTokens`, tablica `personal_access_tokens` migrirana.
- `Job::publicUrl()` (ili ekvivalent) vraća apsolutni javni URL stavke.
- Slike moraju imati apsolutne, javno dohvatljive URL-ove (hub ih skida pri renderu).

## Provjera

```bash
php artisan social:issue-hub-token admin@example.com
curl -H "Authorization: Bearer <token>" "https://<stranica>/api/social-feed?limit=3"
```
