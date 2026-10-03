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
- **Čija je ponuda, mora se vidjeti.** Izvor ponude (trgovački lanac, poslodavac, izdavač) dolazi kroz
  opća polja `subtitle` + `images[role=logo]` i ide na pločicu (`templates.partials.provider`) koja se
  mjeri **po obliku samog znaka** (`RemoteImageCache::aspectRatio`, za SVG po `viewBox`-u): široki potpis
  stoji sam i raširi se, kvadratni znak (Lidl) dobije visinu umjesto širine i ime uz sebe. Stari kvadratić
  je široki logotip (SPAR i Konzum su ~5:1) sveo na dvadesetak piksela slova — zato je akcija izgledala
  kao naša roba. Brendov
  logotip **nikad** ne uskače umjesto tuđeg (`provider.logo` nema fallback): hub prenosi ponudu, ne
  prodaje je, i svoj znak nosi u podnožju. Pregled dobije logo lanca samo ako su **sve** stavke istog
  lanca (`TemplateData::sharedProvider`) — inače je to naš izbor, ne njihova kampanja.
- **Slika akcije je pločica iz letka, ne fotografija proizvoda.** Izvor šalje isječak kataloga: nosi trgovčevu
  vlastitu cijenu, sitni tisak, rubne linije i na dnu ostatke *susjedne* pločice (plavu traku, vrhove crvenih
  brojki), a široka je tek 470–640 px. Zato `deal-hook` i `deal` prikazuju cijelu pločicu kao predmet (`.leaflet`),
  nikad razvučenu preko kadra: dno joj se odreže i zafadea (`TemplateData::LEAFLET_CUT`), a neobičan oblik (vrlo
  visok ili širok) ide cijel na zamućenu kopiju sebe (`TemplateData::tileShape`). Naziv i cijena su jedan žuti
  blok, popust je pečat na uglu pločice; raspored po formatu je u `App\Rendering\DealFrame` (pločica dobije
  visinu koja preostane iza trgovine, bloka i činjenica), ne u CSS-u. Uz `video_style=direct` kartica ne pečati
  popust drugi put, a ključ hooka mora ostati u regexu u `VideoRenderer` (uvod 2,5 s).
- **Usporedba crta rang, ne samo ispisuje.** Popis je niz traka: duljina prati brojku od nule (najkraća = najjeftinija,
  žuta), ali nikad ispod `ComparisonFrame::MIN_BAR` jer bi u nju stali samo logotip i broj. Kad je ijedna brojka nešto
  drugo od običnog broja („od 9,98 €/kg“) trake se ne crtaju uopće — nepošteno bi bilo mjeriti ono što se ne da izmjeriti.
  4:5 i 1:1 nemaju zaseban dizajn: isti se smanjuje (`ComparisonFrame::layout` → `zoom`), a story ostavlja sigurne
  zone aplikacije i nema podnožja. Žuta i crvena su signal trgovine, ne boja brenda (kao u akcijama).
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
- **Link objave na Facebook stranici ide u prvi komentar, ne u tekst** (Facebook objavu s vanjskim
  linkom pokazuje manjem broju ljudi). `CaptionBuilder` piše „👇 … u prvom komentaru“, a
  `FacebookPagePublisher` nakon objave ostavi `settings.first_comment` ili `link_url` — osim kad tekst
  već sadrži taj link. Komentiranje kao stranica traži `pages_manage_engagement`. Grupe (ručni kanal)
  zadržavaju link u tekstu.
- **`expires_at` je stara koliko i zadnja sinkronizacija koja je stavku stvarno vidjela.** Izvor koji
  stavku tiho prestane vraćati (arhiviranje, brisanje) nikad ne dobije priliku ispraviti taj datum —
  hub samo prestane čuti za nju. Zato `PublishVariantJob` neposredno prije objave provjerava i
  `App\Publishing\LinkPreflight` (stvaran HTTP poziv na `content_item.url`), ne samo `isExpired()`;
  promašaj ide u `Skipped` s `error_code=dead_link`, isto kao istekla stavka. Isto se pita i **pri
  odabiru** (`LinkPreflight::isAlive()` u `DigestBuilder::pick` i `ApplyAutoPublishRules`): mrtva
  stavka ustupa mjesto sljedećoj umjesto da jedna sruši cijeli pregled na svim kanalima, a 404/410 se
  pamti u `content_items.link_dead_at` (izvan `live()`) dok je sinkronizacija ponovno ne vidi. Nacrt
  kojem su svi kanali preskočeni prelazi u `DraftStatus::Skipped`, ne ostaje „objavljuje se".
- **Instagram token je Page token.** Discovery sprema isti token na `fb_page` i `ig_business` račun, plus
  `connected_user_id` koji deauthorize callback koristi da nađe pogođene račune.
- **Facebook odobrenje je jedno po korisniku × aplikaciji, ne po brendu.** Svaka prijava (dijalog ili ručni
  token) zamjenjuje popis stranica koje odobrenje pokriva; Page token stranice koja u dijalogu nije označena
  umire s `(#190) … permission(s) must be granted before impersonating a user's page` (bez podkoda, `expires_at`
  = never, `debug_token` → `is_valid=false`, u `granular_scopes` nema te stranice) — tjednima prije bilo kakvog
  isteka. Zato je „moram stalno povezivati" bio to, ne istek: povezivanje Studentskih rušilo je Listo i obrnuto
  (potvrđeno 29. 09. 2026.). `MetaAssetDiscovery` osvježava **sve** poznate stranice koje odobrenje još pokriva,
  i drugih brendova (osim `Disabled` — isključene ili obrisane povezivanje drugog brenda ne budi), a ostale
  provjerava spremljenim tokenom i odmah označava `needs_reconnect` (`DiscoveryResult::lostAccess`) uz poruku
  u panelu (`MetaConnectNotices`). Token koji još odgovara ili nejasan odgovor Grapha ništa ne označavaju.
  Ne vraćati filtriranje na stranice samo povezanog brenda. Dijagnoza: `debug_token` na spremljeni token.
- **Tokeni** su `encrypted` castovi i ne smiju u bazu u čistom obliku: sve što Graph pošalje ili primi
  prolazi kroz `GraphClient::redact()` prije spremanja (`publish_logs`, `post_metrics.raw`) — Graph
  vraća Page token u svakom `paging.next` linku.
- **Vremena**: DB u UTC; Filament pickeri i scheduler `Europe/Zagreb`. Automatika nikad ne bira vrijeme
  sama — `App\Support\PostingSchedule` vraća sljedeći termin unutar `brands.posting_windows`
  (lokalno vrijeme brenda), a batch se razmiče da ne padne sve u istu minutu.
- **Auto-publish je opt-in po izvoru × platformi** (`auto_publish_rules`) i pokreće se samo kad je
  sinkronizacija stvarno donijela nove stavke. Bez toga bi svaki prolaz preispitivao cijeli katalog.
  Iznimka je `sources.auto_publish_backlog` (opt-in): `hub:auto-publish-backlog` u 06:30 rasporedi ono
  što je `daily_cap` zadržao — bez toga stavka koja stigne preko limita nikad ne ide van. Pravilo nosi i
  **format i postavke kanala** (`format`, `settings`); kampanja se slaže u panelu, ne u kodu.
  `daily_cap` je **po kanalu**: stavke idu po prioritetu, pa kanal s malim limitom (2 Reela) dobije
  najbolje, a kanal bez limita (grupe) sve. Nove objave se slažu iza već zakazanih (`PostingSchedule::queueAfter`).
- **Ponuda mora nadživjeti objavu.** `config/hub.php` → `min_days_valid` (`deal` i `comparison`: 3 dana) —
  kupac treba stići kupiti. Mjeri se od **termina kad objava izlazi**, ne od danas: `ContentItem::scopePostableAt()` /
  `isPostableAt()`. `ApplyAutoPublishRules` drži svaku stavku uz termin koji će stvarno dobiti (stavka na rubu
  prođe za 12:00, a za 12:45 ne), `DigestBuilder::pick` uz `scheduledAt`. Oglasi, članci i događaji nisu pod tim
  pravilom (samo `live()`). Agent i MCP ga mjere od sada, jer vrijeme objave određuje čovjek; ručno odabranu
  stavku pravilo ne blokira. Usporedba istječe s prvom ponudom (`expires_at` = najraniji `valid_to`).
- **Dnevni limit brenda** (`brands.daily_post_limit`, `App\Support\DailyPostLimit`): koliko objava automatika smije
  staviti na jedan lokalni dan brenda, **zajedno** — pojedinačne stavke svih izvora i pregledi. `daily_cap` pravila
  to ne može obećati (po kanalu je, a pregledi mu ne podliježu). Broje se nacrti koji izlaze ili su izašli
  (`Approved`…`PartiallyPublished`) po `scheduled_at`, i one koje je napravio čovjek; zaustavlja samo automatiku.
  Kad je dan pun, `ApplyAutoPublishRules` staje, a `ScheduleDigest` preskoči seriju (danas i dan na koji pada
  termin). Prazno = bez limita.
- **Pregledi su automatika, ne novi kanal**: `brands.digests` je lista serija (`App\Drafting\DigestSeries`:
  dani, vrijeme, broj, vrsta, **oznaka**, format FB/IG; TikTok je uvijek video). Gradi ih `hub:auto-digest` (satno, sat
  unaprijed) kroz `App\Actions\ScheduleDigest` — svaku seriju jednom na dan (`post_drafts.digest_series`),
  samo na kanale s uključenim pravilom, bez ručnih, s postavkama pravila. „Top akcije u Kauflandu“ je
  serija s oznakom `kaufland` (opće polje `tags` iz feeda), nikad grana u kodu. Pregled je uvijek set
  slajdova: carousel ili Reel od njih, nikad jedna slika. Najviše 2 stavke dijele istu oznaku osim
  oznake serije (`DigestBuilder::MAX_PER_TAG` — marka, u miješanom pregledu i lanac); ostatak se tek
  onda puni po prioritetu. Uzima i stavke koje su već imale svoju objavu, ali ne dvaput u 7 dana
  (`DigestBuilder::REPEAT_AFTER_DAYS`).
- **Pregledi se mjere, ne pretpostavljaju**: `hub:collect-metrics` (satno) čita objave koje su na redu
  (`CollectPostMetrics::due()`: svakih 6 h prvih 7 dana, zatim dnevno do 30) u `post_metrics`, redak
  po očitanju. Imena Metinih metrika su u `config/meta.php` (`insights`) jer ih Meta mijenja; neuspjeh
  jednog očitanja samo se logira. TikTok video id se dohvaća jednom (`settings.tiktok_video_id`).
- **Jedna stavka kao carousel ili video = set slajdova** (`config/template_sets.php`: udica → kartica →
  poziv na akciju), isti set u 4:5 i 9:16. FB/IG slike su 4:5 (`PrepareVariantMedia::orientation()`),
  TikTok 9:16. Udica i prvi red teksta čitaju isto (`App\Drafting\Highlights`).
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
- **Format je postavka varijante, ne novi kanal**: `settings.format` = `image|carousel|video|link`
  (`App\Enums\ContentFormat`), dopušteni po `Platform::formats()`. Reel je `video` na Metinim kanalima.
  **TikTok je uvijek video** (Marijan, 25. 09. 2026.) — API for Business drugo ni ne objavljuje; pravilo
  ili serija koja traži carousel dobije video od istih slajdova, foto objave na TikToku ne postoje. Uvijek čitaj `PostVariant::format()` — zna i stare
  `format=post|reel` (IG) i `mode=photo|link|reel` (FB).
- **Mediji su po varijanti, ne po nacrtu.** Nikad ne prikači render svim varijantama nacrta: medij
  priprema `App\Actions\PrepareVariantMedia` (ponovno koristi postojeći asset, inače render u
  pozadini; stanje u `settings.render`), a promjena formata ide kroz `ChangeVariantFormat`.
  `App\Publishing\FormatCheck` provjerava da medij odgovara formatu — na ekranu i u
  `PublishVariantJob` prije publishera (renderira se → retry, ne odgovara → `media_not_ready`).
- **TikTok se pita prije objave.** `creator_info/query` daje dopuštene razine privatnosti i najdulje
  trajanje; hub se prilagođava odgovoru umjesto da pretpostavlja. Neauditirana aplikacija smije samo
  `SELF_ONLY` — to se ne zaobilazi.
- **Neauditiranost je trajna na ovom tracku, ne faza.** TikTok je 22. 09. 2026. odbio produkciju jer
  „utility tool to help upload contents to the account(s) you or your team manages" njihove
  Content Sharing Guidelines izrijekom navode kao neprihvatljiv use-case — hub je po definiciji to.
  Ne pisati kod koji pretpostavlja da audit stiže i ne predlagati ponovnu prijavu s drugim opisom.
  Jedini put su Business Center + **Organic API** (`business-api.tiktok.com`, drugi auth tok).
  Oba tracka postoje u kodu i bira ih `TIKTOK_API=developer|business`; Business aplikacija je
  odobrena 23. 09. 2026. — puštanje u produkciju u `docs/tiktok-business-api.md` § 11, pozadina u
  `docs/tiktok.md` („Zid 0"). Na Business tracku `video_url` mora biti unutar verificiranog URL
  prefiksa (`hub:tiktok-url-property`, verificira `storage/media/`); verifikacija je po aplikaciji,
  ne po računu. Prekidač je privremen, ne trajna apstrakcija: kad Business proradi,
  developer track i `TikTokTokens` sučelje idu van.
- **TikTok tokeni istječu**: pristupni 24 h, refresh se rotira pri svakom osvježavanju. Uvijek spremi
  **novi** refresh token; `hub:refresh-tiktok-tokens` radi satno.
- **Zvuk se miksa u hubu, iz licencirane knjižnice brenda** (`brands.audio_tracks`,
  `Brand::audioTrackPath`). API ne može dodati TikTokov zvuk — nema `music_id`, ne tražiti ga.
  Trending zvuk postoji samo kroz inbox (`settings.delivery = inbox`): hub preda video, čovjek
  objavi u aplikaciji, varijanta čeka u `ManualPending` s `external_post_id` (publish_id).

- **Voice-over je dodatak videu, nikad njegov uvjet.** Glas (ElevenLabs, `App\Voiceover\*`, `docs/voiceover.md`)
  stvara `Narrator` u `RenderVideoJob`. Ako ne uspije (nema ključa, plan potrošen, redak odbijen) video se
  renderira bez njega, razlog ide u `media_assets.params.voiceover` (vidi ga urednik na kanalu) i jedan mail
  adminu po uzroku. Ne uvoditi put u kojem objava čeka glas.
- **Glas govori samo ono što piše u podacima stavke.** `ScriptBuilder` piše iz `Highlights`/`price`/`facts` (istog
  što crta slajd), `ScriptGuard` primjenjuje pravila `CaptionValidator` (iznos, postotak, poveznica) i na redak koji
  je urednik izmijenio, prije nego što se išta plati. Nova provjera ide ondje, ne u tekst upute.
- **Brojeve izgovara hub, ne model.** Model dobiva samo riječi (`SpokenCroatian`: „1,49 €“ → „jedan euro i
  četrdeset devet centi“, uz padež i rod). Ne slati znamenke glasu; ime koje se čita krivo ide u „Izgovor imena“
  na brendu.
- **Isječak se plaća jednom.** Jedan redak = jedan zahtjev; `Synthesizer` ga nalazi po sha256 od teksta, glasa,
  modela i postavki u `voiceovers`. U ključ ne ulazi kontekst (`previous_text`): završna rečenica brenda dijeli se
  između svih videa. Knjiga ima dva broja: `characters` je duljina izgovorenog teksta (hub je računa), a `cost` je
  zaglavlje `character-cost` — ono što je zahtjev naplaćen u kreditima, ne broj znakova (isti redak od 43 znaka:
  14 na v2, 9 na v3, 7 na flash, 3 na v4). Potrošnju zbrajaj po `cost`; zaglavlje nikad ne ide u `characters`.
- **Jedan model glasa: ElevenLabs v4** (Marijan, 03. 10. 2026.), i noviji kad izađe. Ime modela je `ELEVENLABS_MODEL` i
  popis `config/elevenlabs.models`, nikad grana u kodu; brend sa spremljenim modelom izvan popisa dobiva zadani. v4 prima
  sve glasovne postavke, ali `speed` ne primjenjuje (isti redak jednako dug uz 0,7 i 1,2) — ne obećavati ubrzanje
  postavkom. v2/v3/flash su maknuti iz panela; ne vraćati ih bez razloga.
- **Video s glasom i video bez glasa su dva asseta.** `PrepareVariantMedia::latestVideo` ih razlikuje po
  `params.voiceover.status`; kanal nikad ne dobije onaj koji nije tražio. Tko odlučuje: `settings.voiceover` kanala →
  pravilo automatske objave (`UpdateVariant::EDITABLE_SETTINGS`) → `brands.voiceover.enabled`.
- **Slajd čeka riječi, ne obrnuto.** `VideoRenderer::fitNarration` produžuje slajd na trajanje njegovog retka (nikad
  kraće nego bez glasa); glazba se ispod glasa stišava `sidechaincompress`-om. Ne rezati retke da stanu u zadano
  trajanje: Facebook Reel smije 90 s, proračun znakova (`elevenlabs.max_characters_per_video`) je ograda za to.
- **Jedan AI provider: OpenAI.** Tekstove objava (`OpenAiCaptionWriter`) i naglaske za voice-over (`Accenter`) traži isti
  klijent, `App\Ai\OpenAiClient` (Responses API, stroga JSON shema, `store: false`). Postavke su u `config/openai.php`
  (`OPENAI_*`), ime modela nikad u kodu. Novi AI poziv ide kroz taj klijent i hvata `OpenAiException`. Anthropic je
  maknut (Marijan, 29. 09. 2026.); ne uvoditi ga natrag kao drugi put.
- **Model smije označiti naglasak, ne promijeniti riječ.** Svaki redak koji glas čita prolazi kroz `Accenter` prije
  ElevenLabsa. Odgovor je mjesto oznake (riječ, samoglasnik), a `Stress::position` odbacuje sve što nije ista riječ s
  jednim naglaskom na samoglasniku. Odgovor se sprema (`voiceover_accents`): model ne odgovori dvaput isto, a redak s
  drugim oznakama je za ElevenLabs novi tekst i novi račun. OpenAI koji ne odgovori znači video bez glasa
  (`accents_*`), ne glas bez naglasaka; jedini put bez OpenAI-ja je *Naglasci: Isključeno* na brendu. Kako glas čita
  oznaku (akut ili veliko slovo) nije provjereno, već se bira uhom: `hub:voiceover-test --compare`.

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
