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
  404/410 ide u `Skipped` s `error_code=dead_link`, isto kao istekla stavka. Stranica koja se samo ne
  može dohvatiti (timeout, 5xx, 408/425/429) baca `TransientPublishException` i prolazi isti ograničeni
  retry kao API, a drugi 4xx je `Failed` (`link_check_failed`) — jedan loš odgovor trgovine ne smije trajno
  ubiti objavu. Isto se pita i **pri
  odabiru** (`LinkPreflight::isAlive()` u `DigestBuilder::pick` i `ApplyAutoPublishRules`): mrtva
  stavka ustupa mjesto sljedećoj umjesto da jedna sruši cijeli pregled na svim kanalima, a 404/410 se
  pamti u `content_items.link_dead_at` (izvan `live()`) dok je sinkronizacija ponovno ne vidi. Nacrt
  kojem su svi kanali preskočeni prelazi u `DraftStatus::Skipped`, ne ostaje „objavljuje se".
- **Nacrt ne izlazi s podacima koje je izvor u međuvremenu promijenio.** `post_draft_content_items.publication_checksum`
  je hash samo onoga što ulazi u objavu (`ContentItem::publicationChecksum()`: tekst, cijena, činjenice, URL, slike,
  `expires_at`, `raw.emoji`/`raw.rows` i `raw.demo`, koji video kataloga ispisuje u cijelosti: nazive, cijene, zbroj,
  datume, „1 / 64“); prioritet i ostalo knjigovodstvo feeda ne računaju se, pa „dodir" bez
  izmjene ne diže uzbunu. Decimalni brojevi se zaokružuju na 6 mjesta jer JSON stupac vraća manje znamenki nego što je
  upisano (`0.38863460386531523` → `0.3886346038653152`), pa bi inače objava iz stavke u memoriji odmah bila „izmijenjena“
  naspram iste stavke iz baze. Kad se formula mijenja, migracija prežigosa objave čija stavka od nastanka nije
  izmijenjena (`2026_10_15_000001_stamp_catalog_demo_into_publication_checksums`), inače bi sve nepublicirane objave
  odjednom bile „zastarjele“. `PostDraft::staleItems()` je usporedba; `PublishVariantJob` varijantu koja ne odgovara
  preskače s `error_code=stale_content` (`PublishVariantJob::contentBlock`, uz alkohol), jer automatika nikad ne vidi upozorenje iz panela.
  Nema toka „osvježi nacrt": put naprijed je novi nacrt iz stavke. Razmatrano i odbačeno 14. 10. 2026.
  (sinkroni render u web zahtjevu, prepisivanje ručnih izmjena, poništavanje odobrenja pri svakom syncu).
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
- **Citat je opći oblik, ne sadržaj neke domene.** `kind=quote`: `title` je sam tekst, `subtitle` čiji je ili odakle.
  Hub ne zna što je tekst, na kojem je jeziku ni iz kojeg je prijevoda — stihovi, kalendar i licenca žive u izvoru.
  Zato `CaptionBuilder::quote` ne piše nijednu vlastitu riječ (redak ispod teksta je `cta.label` izvora, na njegovu
  jeziku), a predložak ne mijenja velika/mala slova reference („Iv 3,16“ velikim slovima je druga knjiga).
  Kartica citata drži se koliko treba za čitanje (`Scene::readingSeconds`, najviše 10 s: izvor šalje kratke tekstove,
  hub ih ne reže). „Jedan citat dnevno uz pregled“ je postavka, ne kod: pravilo s `daily_cap` 1 i „Traži odobrenje“,
  izvor koji daje prozor [danas, sutra] s `priority` što pada s datumom i `expires_at` = kraj dana. Pazi:
  `isPostableAt()` za vrste bez `min_days_valid` ne mjeri `expires_at` prema terminu, samo prema sada — nacrt
  napravljen nakon prozora objave dobije termin sutra i `PublishVariantJob` ga preskoči kao istekao.
- **Dnevni limit brenda** (`brands.daily_post_limit`, `App\Support\DailyPostLimit`): koliko objava automatika smije
  staviti na jedan lokalni dan brenda, **zajedno** — pojedinačne stavke svih izvora i pregledi. `daily_cap` pravila
  to ne može obećati (po kanalu je, a pregledi mu ne podliježu). Broje se nacrti koji izlaze ili su izašli
  (`Approved`…`PartiallyPublished`) po `scheduled_at`, i one koje je napravio čovjek; zaustavlja samo automatiku.
  Kad je dan pun, `ApplyAutoPublishRules` staje, a `ScheduleDigest` preskoči seriju (danas i dan na koji pada
  termin). Prazno = bez limita.
- **Lineup po terminima** (`brands.auto_lineup`, `App\Actions\AutoLineup`): brend s uključenim prekidačem ne puni
  stavke odmah po sinkronizaciji, nego po terminu objave (`brands.posting_windows`). Termin se puni 30 min prije
  otvaranja (sinkronizacija, ingest i `hub:auto-lineup` u :30). Dnevni limit se dijeli po terminima (5 nad 3 daje
  2, 2, 1; limit manji od broja termina daje jednu po terminu). Vrsta ide po `brands.lineup_priority` (zadano
  katalog, usporedba, akcija); vrsta koja nije na popisu se ne objavljuje. Kandidati se čitaju **vrstu po vrstu**
  (do 100 po vrsti), ne jedan bazen preko vrsta: inače bi sto akcija višeg prioriteta zauvijek zaklonilo katalog koji
  brend stavlja prvim. Stavka kataloga bez `raw.demo` i stavka koju nijedan otvoreni kanal ne uzima (alkohol,
  `RestrictedGoodsCheck`) preskaču se prije nego što se nacrt spremi, da termin ne ostane zauzet objavom koju bi
  `PublishVariantJob` ionako preskočio. Prozori se računaju po satu na zidu (`PostingSchedule::wallClock`), nikad
  zbrajanjem minuta od ponoći: dan promjene vremena ima 23 ili 25 sati pa bi prozor od 08:00 otvorio u 07:00 ili 09:00.
  `ApplyAutoPublishRules` za ostale brendove radi kao prije.
- **Pregledi su automatika, ne novi kanal**: `brands.digests` je lista serija (`App\Drafting\DigestSeries`:
  dani, vrijeme, broj, vrsta, **oznaka**, format FB/IG; TikTok je uvijek video). Gradi ih `hub:auto-digest` (satno, sat
  unaprijed) kroz `App\Actions\ScheduleDigest` — svaku seriju jednom na dan (`post_drafts.digest_series`),
  samo na kanale s uključenim pravilom, bez ručnih, s postavkama pravila. „Top akcije u Kauflandu“ je
  serija s oznakom `kaufland` (opće polje `tags` iz feeda), nikad grana u kodu. Pregled je uvijek set
  slajdova: carousel ili Reel od njih, nikad jedna slika. Najviše 2 stavke dijele istu oznaku osim
  oznake serije (`DigestBuilder::MAX_PER_TAG` — marka, u miješanom pregledu i lanac); ostatak se tek
  onda puni po prioritetu. Uzima i stavke koje su već imale svoju objavu, ali ne dvaput u 7 dana
  (`DigestBuilder::REPEAT_AFTER_DAYS`). Jedan pregled dijeli stavke među kanalima, pa uzima samo izvore
  čije (uključeno) pravilo dopušta objavu na **svakom** ciljnom kanalu serije; dozvola jednog izvora na jednom
  kanalu nikad ne objavljuje stavke drugog izvora (`ScheduleDigest` → `DigestBuilder::pick($sourceIds)`).
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
  ne po računu. Dok postoje računi oba tracka, račun pamti koji ga je API izdao (`meta.api`,
  `SocialAccount::isTikTokBusiness()`) i objava, osvježavanje tokena, mjerenje, gumb „Provjeri“ i prvi komentar
  (`LeaveTikTokCommentJob`; račun developer tracka dobiva razlog `developer_account` i mail s tekstom) idu po
  računu, ne po globalnom `TIKTOK_API`. Mjerenje Business računa
  nije implementirano (`TikTokMetrics::supports()`): collector ih preskače, a „nema očitanja" nije nula.
  Prekidač je privremen, ne trajna apstrakcija: kad Business proradi, developer track i `TikTokTokens`
  sučelje idu van.
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
- **Jedan AI provider: OpenAI.** Tekstove objava (`OpenAiCaptionWriter`) i izgovor riječi za voice-over (`Phonetizer`) traži isti
  klijent, `App\Ai\OpenAiClient` (Responses API, stroga JSON shema, `store: false`). Postavke su u `config/openai.php`
  (`OPENAI_*`), ime modela nikad u kodu. Novi AI poziv ide kroz taj klijent i hvata `OpenAiException`. Anthropic je
  maknut (Marijan, 29. 09. 2026.); ne uvoditi ga natrag kao drugi put.
- **Izgovor riječi bira čovjek, uhom; hub ga umeće u tekst.** Brend ima popis riječ → IPA (`brands.voiceover.words`, panel *Riječi
  s ručnim izgovorom*). `Phonetizer::prepare` ga umetne u svaki redak koji ima tu riječ, **poslije** *Izgovor imena* a prije ElevenLabsa:
  riječ koju izgovor imena daje glasu (`SpokenCroatian::pronounced`) ne dobiva IPA ni s popisa ni od modela. Umetanje je `Ipa::render` kao
  `tag` (`<phoneme alphabet="ipa" ph="…">riječ</phoneme>`, zadano), `slash` (`/…/`) ili `bare`. Tekst s IPA-om čita **samo** model iz
  `elevenlabs.ipa_models` (sada samo `eleven_v4`); drugi model dobiva tekst bez IPA-a. Nema ElevenLabsova rječnika (nema DELETE-a,
  verzije su zaključane — to je bila slijepa ulica). Pravilo je za **točan oblik riječi** (bilo koja veličina slova): padeži su zasebni
  retci. `Ipa::sanitize` pazi da IPA ne može prekinuti oznaku. Ključ isječka je tekst s IPA-om, pa izmjena izgovora plaća samo retke s
  tom riječi. OpenAI **nikad sam ne piše u tekst**: `ipa_auto` (`VOICEOVER_IPA_AUTO`, zadano isključen; `Phonetizer::marks`) je
  eksperiment koji se uključuje ručno, a `Ipa::clean` (IPA mora nalikovati riječi) drži sve što model vrati. OpenAI služi za ✨ prijedlog
  IPA-a jedne riječi u panelu (`IpaSuggester`), koji čovjek potvrđuje uhom prije spremanja. Zašto ne model: 2. 10. 2026. model je sam
  odabrao Listo i Konzum (ne letku), a obični tekst je zvučao bolje, dok je ručno odabran IPA za letak/letka u Listo TikTok reklami
  zvučao bolje. Kako koji zapis zvuči nije provjereno, već se bira uhom: `hub:voiceover-test --word="letka=ˈlɛtka" --compare`.
  Ako `ipa_auto` zakaže, video izlazi bez glasa (`ipa_*`).

- **Video „izašao je novi katalog“ je scena, ne slideshow** (`docs/catalog-video.md`). Izvor šalje `kind: catalog` s
  blokom `raw.demo` (tri proizvoda koje dodir doista dodaje: Listo ih bira istom logikom kao aplikacija); hub ga čita
  strogo (`CatalogDemo`) i odbija blok čiji `total_cents` nije zbroj cijena. Video crta Chromium kao funkciju od `t`
  (`resources/catalog-video/scene.js`, kopija zaslona aplikacije u mjerama iz njezina koda), ffmpeg miksa i kodira.
  Sva vremena su u `CatalogVideoPlan` i svako je na cijelom kadru: zvuk dodavanja ide na kadar dodira, rečenica nikad
  ne prelazi u iduću scenu, video čeka riječi. Tekst glasa, titlova, naslova i objave piše samo `CatalogCopy`
  (poziv je „Poveznica do aplikacije je u komentaru“, ne cijena ni ušteda). Glas je dodatak, ne uvjet: bez njega isti
  video s titlovima.
- **Jedna poveznica po videu i kanalu** (`TrackedLink`): `utm_source` po kanalu, `utm_medium=social`, kampanja iz
  izvora; sprema se u `post_variants.link_url` i `settings.tracking`. **Iznimka su TikTok i Instagram**
  (`catalog_video.link_typed`, Marijan 7. 10. 2026.): u njihovu komentaru se ne može dodirnuti ništa, pa ga ljudi prepisuju,
  a poveznica s upitom se ne prepisuje — komentar kaže samo `uselisto.com/app` (`CatalogCopy::typeable`), a `settings.tracking`
  se ne sprema jer komentar ne nosi oznaku. Cijena: Listo ta dva kanala ne razlikuje. Facebook komentar se dodiruje i ostaje
  praćen. Prvi komentar je poveznica: na Facebooku i
  Instagramu ga ostavlja publisher, na TikToku `LeaveTikTokCommentJob` (`business/comment/create/`, tri minute nakon
  objave, uz provjeru da komentar nije `HIDDEN`) tek kad je `TIKTOK_BUSINESS_COMMENTS=true`, tj. kad aplikacija ima dozvolu
  za komentare; inače i kad komentar ne uspije admin dobije mail s točnim tekstom za lijepljenje (obećanje videa mora
  vrijediti). Komentar nikad ne ruši varijantu: objava je već javna.
- **`auto_publish_rules.requires_approval`** (zadano uključeno): nacrt se sam napravi i renderira, ali čeka čovjeka;
  jedno pravilo koje traži odobrenje drži cijeli nacrt. Postojeća pravila su migracijom ostala bez odobrenja. Pregledi
  (serije) ne čitaju prekidač. Letak je „vijest“ tri dana (`hub.max_age_days`), da prva sinkronizacija ne stavi u red
  videe za sve što izvor već drži.
- **Oznaka AI-generiranog sadržaja**: `PostVariant::aiGenerated()` (video sa sintetičkim glasom) → TikTok
  `is_ai_generated`/`is_aigc`, Instagram `is_ai_generated`; Facebook Reels API nema parametar.

## Panel (izgled)

- **Forma je jedna kolona.** Resource stranice daju formi 2 kolone ako se ne kaže drukčije, pa se sekcije različite
  visine slože jedna uz drugu s velikim prazninama na desktopu. Svaka `Schema` u `app/Filament/Resources/*/Schemas` zove
  `->columns(1)`; što je dugo ide u `Tabs` po temi (brend: Osnovno / Tekstovi objava / Video i zvuk / Voice-over /
  Raspored objava), ne u još jednu sekciju. Create/Edit stranice koriste `ReadableFormWidth` (5xl), a panel je pune
  širine zbog tablica i kalendara.
- **Vlastiti prikazi nemaju inline stilova ni Tailwind klasa.** Filamentov CSS sadrži samo klase koje Filament sam
  koristi (nema `p-4`), a deploy radi `filament:assets`, ne Vite build. Zato vlastiti prikazi koriste Filamentove
  komponente (`x-filament::section`, `callout`, `empty-state`) i klase `hub-*` iz `resources/css/hub-admin.css`
  (`FilamentAsset` u `AdminPanelProvider::boot`, objavljuje se u `public/css/app`). Boje su Filamentove varijable
  (`--primary-600`, `--gray-200`), pa isti stil radi u tamnoj temi i na mobitelu. Novi razmak ili boja idu ondje.
- **Korisnik vidi „objavu“, ne „nacrt“.** `PostDraft` je u panelu *objava*, „Nacrt“ ostaje samo status; `ContentItem`
  je *stavka* (izbornik „Sadržaj iz izvora“), `PostVariant` je *kanal*. Izbornik ide po toku rada (Objavljivanje →
  Sadržaj → Postavke), ne po tablicama. Izvor je u Sadržaju jer je dio toka, a ne podešavanje.
- **Nadzorna ploča mjeri `App\Filament\Support\HubStatus`.** Brojke i vodič „Kako početi“ dolaze odande; nova stvar
  koja traži pažnju ide u `HubStatus` i `AttentionOverview`, ne kao zaseban upit u widgetu. Brojka i popis koji otvara
  dijele jednu definiciju (`PostDraft::scopeNeedingFix` za „Nije uspjelo“ i tab „Neuspjelo“): „Djelomično objavljeno“ je
  greška samo ako je kanal pao, a objava koja čeka ručni kanal (FB grupa) je običan dan. Rok računa nije `token_expires_at`
  svakog računa: TikTokov (24 h) i YouTubeov (1 h) pristupni token hub obnavlja sam, pa je rok refresh token, a Metin token
  bez obnove ima rok na svom datumu. Tablice na ploči su pregled na prvi pogled, bez stupca kanala; kratke nazive daje
  `Platform::shortLabel()` (`PlatformBadges`).
- **Zašto objava nije izašla piše u sučelju, ne samo u šifri.** Kanal koji je preskočen ili pao pokazuje poruku i „Što
  učiniti“ (`App\Publishing\FailureAdvice`), a popis objava stupac „Razlog“. Savjet imaju samo šifre čiji je uzrok poznat iz
  koda koji ih postavlja; platformina vlastita greška ostaje kakva je došla, jer bi nagađanje poslalo čovjeka krivim putem.
  Novi kod s poznatim uzrokom dobiva rečenicu ondje, uz test.
- **Izgled se provjerava alatom, ne dojmom.** Nakon izmjene panela:
  `AUDIT_EMAIL=… AUDIT_PASSWORD=… node scripts/panel-audit.mjs` (lokalni panel, korisnik iz `HUB_ADMIN_EMAILS`, baza s
  ponešto od svega; Chrome iz `HUB_CHROME_PATH` ili Puppeteerova cachea; `--only=/admin/brands` za jednu stranicu) otvara svaku
  stranicu na laptopu i telefonu, u svijetloj i tamnoj temi, i javlja preljev stranice, sekcije nejednake visine jednu uz
  drugu, greške u konzoli i WCAG A/AA (axe). Bez preglednika čuvaju `PanelLayoutTest` (svaka forma je jedna kolona) i
  `ListQueryCountTest` (popis ne postavlja upit po retku: kad se brend spoji pod naslov, relacija se više ne učitava sama,
  pa je `->with('brand')` dio svakog takvog popisa). Ćelija klikabilnog retka koja ostane bez teksta i slike je prazna
  poveznica za čitač zaslona: stupac dobiva `placeholder('—')`, slika `defaultImageUrl` (`Placeholders::thumbnail()`).

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
