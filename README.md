# Social Hub

Jedno mjesto za objave na društvenim mrežama za više brendova (studentski-poslovi.hr, radim.hr,
uselisto.com…). Hub povlači sadržaj sa stranica kroz **Social Feed v1**, renderira brendirane slike,
nudi pregled/uređivanje/odobrenje i objavljuje na **Facebook Page** i **Instagram** (post i carousel).
Facebook grupe nemaju API — hub pripremi tekst i sliku, čovjek zalijepi i označi.

Plan i odluke: `~/.claude/plans/imam-tri-projkekta-radim-hr-pure-quill.md`.

## Stack

Laravel 13 · PHP 8.4 · MySQL · Filament 5 · spatie/browsershot (Chromium) · spatie/image · Sanctum ·
laravel/mcp · Anthropic PHP SDK. Lokalno Sail (Docker), produkcija Ploi bez Dockera
([docs/deploy-ploi.md](docs/deploy-ploi.md)).

## Lokalno

```bash
./vendor/bin/sail up -d                 # app http://localhost:8100, MySQL 33307
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan make:filament-user      # e-mail mora biti u HUB_ADMIN_EMAILS (.env)
./vendor/bin/sail artisan hub:doctor
docker compose exec -d -u sail laravel.test php artisan queue:work --queue=publish,render,default
```

Panel: http://localhost:8100/admin

## Naredbe

| Naredba | Što radi |
|---|---|
| `hub:source-test {izvor}` | Dohvati 1 stranicu feeda, validiraj po JSON Schemi, pokaži uzorke i upozorenja |
| `hub:sync-sources [--now] [--source=]` | Povuci nove/izmijenjene stavke (queue ili odmah) |
| `hub:draft-digest {brend} [--kind=] [--count=]` | Jedan carousel od više stavki („Top 5 akcija ovog tjedna") |
| `hub:render-preview {predložak} [--item=] [--html]` | Renderiraj predložak za stavku |
| `hub:publish-due` | Pošalji zakazane nacrte u red (svake minute iz schedulera) |
| `hub:verify-accounts` | Provjeri Meta tokene, označi one koje treba ponovno povezati |
| `hub:render-video [--brand=] [--kind=] [--count=] [--seconds=]` | Uspravni 9:16 slideshow (MP4) za Reels i TikTok |
| `hub:refresh-tiktok-tokens` | Osvježi TikTok tokene prije isteka (satno iz schedulera) |
| `hub:agent-draft [brend] [--limit=] [--dry-run]` | Claude piše tekstove za nove kandidate i ostavlja ih na odobrenje |
| `hub:issue-mcp-token {email} --scope=` | Token za MCP servera (read / draft / approve / publish) |
| `hub:doctor` | Provjeri sve od čega objava ovisi |

## Struktura

```
app/Sources      Social Feed v1 adapter, RSS/Atom/JSON Feed adapter, validator, sinkronizacija
app/Support      PostingSchedule: kad brend smije objavljivati (termini po lokalnom vremenu)
app/Rendering    Blade predlošci po vrsti sadržaja → Browsershot → JPEG; ffmpeg → 9:16 MP4 za Reels i TikTok
app/Drafting     deterministički captioni i digest (baza za AI agenta)
app/Actions      CreateDraft, ApproveDraft, ScheduleDraft, DispatchDraftPublishing, MarkManualPosted, DiscardDraft
app/Publishing   Graph i TikTok klijenti, mapiranje grešaka, Facebook/Instagram/TikTok publisheri, OAuth
app/Http/Controllers/Meta  OAuth spajanje računa, deauthorize i data-deletion callbackovi
app/Jobs         SyncSourceJob, RenderMediaJob, PublishVariantJob
app/Filament     panel: kandidati, objave (pregled), kalendar, brendovi, izvori, računi
app/Ai           agent koji piše tekstove (Claude) i validator koji brani izmišljene iznose
app/Mcp          MCP server: hub kroz AI agenta (docs/mcp.md)
docs/            social-feed-v1.md + schema, deploy-ploi.md, examples/laravel-social-feed
```

## Kako sadržaj ulazi

| Ulaz | Kada |
|---|---|
| **Social Feed v1** (pull) | Zadano. Stranica implementira `GET /social-feed`; hub je čita satno. |
| **RSS / Atom / JSON Feed** (pull) | Stranica već ima feed i ne može dobiti endpoint. Sve postaje `kind=article`. |
| **Webhook** (push) | Stranica šalje `POST /api/ingest/{izvor}` s HMAC-SHA256 potpisom tijela u `X-Signature`. |

Novi brend ne traži kod u hubu: Brend → Izvor → **Testiraj vezu** → Poveži račune.

## Automatska objava

Isključena je dok je čovjek ne uključi, i to **po izvoru i platformi** (Postavke → Izvori →
Automatska objava). Kad je uključena, nove stavke iz sinkronizacije same postaju objave i
zakazuju se u sljedeći termin brenda, s odgodom i dnevnim ograničenjem koje zadaš. Objave se
razmiču 45 minuta da deset novih oglasa ne postane deset objava u istoj minuti.

## Objavljivanje

| Kanal | Kako |
|---|---|
| Facebook Page | `/photos` za jednu sliku, neobjavljene fotke + `attached_media` za više, `/feed` za link-post |
| Instagram | spremnik → čekanje na `status_code=FINISHED` → `media_publish`; carousel = spremnik po slajdu + roditelj; opcionalni prvi komentar |
| Facebook grupa | ručno: kopiraj tekst, preuzmi sliku, označi kao objavljeno |
| Instagram Reels | isti spremnik s `media_type=REELS`, naslovnica je prvi slajd videa |
| Facebook Reels | upload sesija na `rupload.facebook.com`, pa `finish` s opisom |
| TikTok | `creator_info` → `video/init` (PULL_FROM_URL) → čekanje na `PUBLISH_COMPLETE` |

Prije svakog Instagram poziva hub provjeri format, omjer, veličinu, duljinu teksta, broj hashtagova i
dnevnu kvotu, pa greške dolaze kao razumljiva poruka, a ne kao Metin „Invalid parameter".

## AI agent

`hub:agent-draft` svakog jutra uzme nove kandidate, zamoli Claudea da napiše tekstove i ostavi ih
kao nacrte **na odobrenje** — agent nikad ne objavljuje. Uključuje se po brendu (Brend → Glas brenda
→ „AI piše nacrte") i traži `ANTHROPIC_API_KEY`.

Prije nego tekst uđe u nacrt, `App\Ai\CaptionValidator` provjeri ono što se modelu ne vjeruje na
riječ: svaki iznos, postotak i poveznica u tekstu moraju postojati u podacima stavke. Ako ne postoje,
tekst se odbacuje, model dobije razlog i jedan pokušaj više, a nakon toga se koristi deterministički
tekst iz `CaptionBuilder`. Objava tako ne može tvrditi cijenu koju izvor nije naveo.

Agenti mogu i sami voditi hub kroz MCP server — alati, opsezi tokena i primjer rutine su u
[docs/mcp.md](docs/mcp.md).

## Video, Reels i TikTok

`hub:render-video` renderira postojeće predloške uspravno (1080×1920) i spoji ih ffmpegom u MP4 s
prijelazima, tihim zvučnim zapisom i `faststart` zaglavljem — jedan video ide na sva tri mjesta.
Na nacrtu je to akcija **Renderiraj video**, a kanal se prebaci na reel u postavkama varijante.

TikTok traži dvoje što se ne rješava kodom: prolazak **audita** (do tada su objave samo privatne) i
**verificiranu domenu** s koje TikTok povlači video. Detalji su u [docs/tiktok.md](docs/tiktok.md).

## Testovi

```bash
./vendor/bin/sail artisan test            # uključuje pravi Chromium render (grupa "render")
./vendor/bin/sail bin pint --test
```
