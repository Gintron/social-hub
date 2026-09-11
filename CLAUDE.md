# Social Hub — upute za agente

Laravel 13 / PHP 8.4 / Filament 5 hub za objave na društvenim mrežama za više brendova. Plan i odluke:
`~/.claude/plans/imam-tri-projkekta-radim-hr-pure-quill.md`; ugovor izvora `docs/social-feed-v1.md`.

## Pravila koja nisu očita iz koda

- **Jedan ugovor, jedan adapter.** Novi brend nikad ne dobiva vlastiti adapter: stranica implementira
  Social Feed v1, hub ga čita kroz `App\Sources\SocialFeedV1Source`. Domenske stvari (oglas, akcija) ostaju
  na stranici; hub zna samo za `ContentItem` s općim poljima (`kind`, `facts`, `badges`, `price`…).
- **Izvor smije nositi vlastite filtere, adapter ostaje jedan.** `SocialFeedV1Source` šalje sve
  `query.*` ključeve iz `sources.config` (i sve što stoji u query stringu `base_url`-a) uz svaki
  zahtjev; `since`/`cursor`/`limit` iz ugovora imaju prednost. Novi filter je unos u panelu, nikad
  grana u kodu.
- **Predlošci slika su po `kind`, ne po brendu** (`config/templates.php`, `resources/views/templates/kinds`).
  Brend daje boje i logo. Brend-specifični predložak je iznimka s vlastitim ključem.
- **Sve mutacije nacrta idu kroz `App\Actions\*`** — Filament, MCP alati i agent zovu iste klase.
- **Objavljivanje je idempotentno**: `PostVariant::claimForPublishing()` (atomski queued→publishing),
  skip kad `external_post_id` postoji, `ShouldBeUnique` jobovi. Draft status se **postavlja prije**
  dispatcha (sinkroni red bi ga inače prepisao).
- **Facebook grupe su ručni kanal** (`Platform::isManual()`): nema API-ja od 04/2024, ne dodavati browser
  automatizaciju.
- **Meta dohvaća slike URL-om**: medij mora biti na javnom disku s https URL-om; Instagram prihvaća samo
  JPEG omjera 4:5–1.91:1, ≤8 MB, 100 objava/24 h. Sve to provjerava `InstagramPreflight` **prije** prvog
  Graph poziva — dodaj novo pravilo ondje, ne u publisher.
- **Instagram objavljuje u dva koraka i asinkrono**: spremnik se mora čekati (`status_code`) prije
  `media_publish`; carousel dodatno čeka svaki slajd prije nego ih roditelj referencira. Polling koristi
  `Illuminate\Support\Sleep` da bi testovi mogli `Sleep::fake()`.
- **Nakon objave ništa ne smije srušiti varijantu**: permalink i prvi komentar hvataju iznimku i samo
  logiraju — objava je već javna.
- **`expires_at` je stara koliko i zadnja sinkronizacija koja je stavku stvarno vidjela.** Izvor koji
  stavku tiho prestane vraćati (arhiviranje, brisanje) nikad ne dobije priliku ispraviti taj datum —
  hub samo prestane čuti za nju. Zato `PublishVariantJob` neposredno prije objave provjerava i
  `App\Publishing\LinkPreflight` (stvaran HTTP poziv na `content_item.url`), ne samo `isExpired()`;
  promašaj ide u `Skipped` s `error_code=dead_link`, isto kao istekla stavka.
- **Instagram token je Page token.** Discovery sprema isti token na `fb_page` i `ig_business` račun, plus
  `connected_user_id` koji deauthorize callback koristi da nađe pogođene račune.
- **Tokeni** su `encrypted` castovi i redigiraju se u `publish_logs` (`GraphClient::log`).
- **Vremena**: DB u UTC; Filament pickeri i scheduler `Europe/Zagreb`. Automatika nikad ne bira vrijeme
  sama — `App\Support\PostingSchedule` vraća sljedeći termin unutar `brands.posting_windows`
  (lokalno vrijeme brenda), a batch se razmiče da ne padne sve u istu minutu.
- **Auto-publish je opt-in po izvoru × platformi** (`auto_publish_rules`) i pokreće se samo kad je
  sinkronizacija stvarno donijela nove stavke. Bez toga bi svaki prolaz preispitivao cijeli katalog.
- **`ContentItem::imageUrl()` ne vraća zamjenu za tuđu ulogu**: traženje `logo` bez logotipa vraća
  `null`, ne prvu sliku — inače proizvod završi u logo pločici svakog predloška.
- **Chromium u Sailu** je Playwrightov arm64 build na `/usr/local/bin/hub-chrome` (Chrome for Testing nema
  arm64 Linux build); produkcija koristi Puppeteerov cache. Ne mijenjati bez čitanja `docker/8.4/Dockerfile`.

- **Agent piše, čovjek objavljuje.** `hub:agent-draft` uvijek proizvodi `pending_approval`; objavljivanje
  bez čovjeka postoji samo kroz `auto_publish_rules`, koje se uključuju ručno. Ne miješati to dvoje.
- **`CaptionValidator` je zadnja brana, ne ukras.** Svaki iznos, postotak i poveznica u tekstu moraju
  postojati u podacima stavke; novo pravilo ide ondje, a ne u prompt (prompt već govori isto, ali
  „obično posluša" nije svojstvo koje objava smije imati).
- **MCP alati zovu iste `app/Actions/*` klase kao Filament.** Novi alat ne smije pisati u bazu izravno,
  inače se pravila (idempotencija, prijelazi statusa, dnevnik) razilaze između sučelja i agenta.
- **Opseg tokena je stvarna granica**: `mcp` čita, `mcp:draft` piše nacrte, `mcp:approve` odobrava,
  `mcp:publish` objavljuje. Alat provjerava ovlast na ulazu (`App\Mcp\Support\Ability`).

- **Video je isti sadržaj, druga visina.** `VideoRenderer` spaja postojeće predloške renderirane u
  1080×1920; nema zasebnog dizajna za video. Izlaz mora ostati H.264/yuv420p + AAC + `faststart`,
  inače ga uploaderi odbijaju.
- **Reel je postavka varijante, ne novi kanal**: `settings.format = reel` na Instagramu,
  `settings.mode = reel` na Facebook stranici. Račun ostaje isti račun.
- **TikTok se pita prije objave.** `creator_info/query` daje dopuštene razine privatnosti i najdulje
  trajanje; hub se prilagođava odgovoru umjesto da pretpostavlja. Neauditirana aplikacija smije samo
  `SELF_ONLY` — to se ne zaobilazi.
- **TikTok tokeni istječu**: pristupni 24 h, refresh se rotira pri svakom osvježavanju. Uvijek spremi
  **novi** refresh token; `hub:refresh-tiktok-tokens` radi satno.

## Rad

- Sve kroz Sail: `./vendor/bin/sail artisan …`, `./vendor/bin/sail bin pint`, `./vendor/bin/sail artisan test`.
- Testovi idu na MySQL bazu `testing` (Sail je stvara iz `docker/mysql/create-testing-database.sql`).
- Pint preset je u `pint.json` (isti kao studentski-poslovi): `declare(strict_types=1)`, `final` klase,
  `mb_*` funkcije.
- Filament 5 API: `Filament\Schemas\Schema` + `->components()`, layout komponente u `Filament\Schemas\Components`,
  sve akcije `Filament\Actions\Action`, forme akcija kroz `->schema()`, tablice `->recordActions()` /
  `->toolbarActions()`.
- Referentna implementacija feeda za Laravel stranice: `docs/examples/laravel-social-feed/` — kad se
  promijeni u studentski-poslovi, osvježi kopiju.
