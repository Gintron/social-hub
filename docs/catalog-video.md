# Video „izašao je novi katalog“

Čim izvor objavi novi letak trgovačkog lanca, hub sam slaže 9:16 video od oko 16 s u kojem se na **pravom
letku** dodirom dodaju tri proizvoda na listu, i priprema ga za TikTok, Instagram (Reels) i Facebook stranicu.
Cilj je instalacija aplikacije, pa video završava jednom radnjom: *Preuzmi Listo* i gdje je poveznica.

Odluke vlasnika (Marijan, 3. 10. 2026.): glas Luka (ElevenLabs `ZLYZToA7aDsMbHwM9AOr`, model `eleven_v4`), sva
tri kanala, poziv na kraju je „Poveznica do aplikacije je u komentaru.“ (ne „20 dana besplatno…“), cilj je
potpuna automatizacija, ali prvih nekoliko videa se pregledava (prekidač *Traži odobrenje*, zadano uključen).

## Tijek

```
Listo feed (kind=catalog)  →  hub:sync-sources (satno)  →  ContentItem(kind=catalog)
      ↓                                                         ↓
 tri odabrana dodira                           auto_publish_rules (izvor × kanal)
 u raw.demo                                                     ↓
                                    PostDraft (čeka odobrenje) + varijanta po kanalu
                                                                ↓
                                 RenderCatalogVideoJob: glas → scena (Chromium) → miks → ffmpeg
                                                                ↓
                              MediaAsset video/catalog-demo  →  odobrenje  →  objava u terminu
```

1. **Izvor.** Listov `GET /v1/social-feed?kind=catalog` vraća jednu stavku po letku (`kind: catalog`, ugovor u
   [`social-feed-v1.md`](social-feed-v1.md) § Katalog). Hub ga čita istim `SocialFeedV1Source` adapterom kao sve
   ostalo; filteri su postavke izvora (`query.kind`, `query.min_products`, `query.max_validity_days`).
2. **Nacrt.** Nova stavka kroz `ApplyAutoPublishRules` postaje nacrt s varijantom po kanalu (`CreateDraft`):
   format je video (Facebook grupa, koja video ne prima, dobiva link). Termin je sljedeći termin objave brenda.
3. **Render.** `PrepareVariantMedia` šalje `RenderCatalogVideoJob` na red `render`; kanali koji kažu isto dijele jedan video.
4. **Objava.** Kao i svaka druga: odobrenje, `PublishVariantJob`, idempotentno.

## Što je u videu

Vremena nisu fiksna nego se slažu prema govoru (`App\Catalog\CatalogVideoPlan`): sličica čeka riječi, kao u
slideshowu. S pravim glasom (Luka, `eleven_v4`, rečenice 2,69 / 4,05 / 1,23 / 2,69 s) video traje oko 16–17 s.

| Što | Kad (primjer, Konzum 7.–13. 10.) | Što se vidi | Što se čuje |
|---|---|---|---|
| Vijest | 0 – 2,8 s | cijeli zaslon aplikacije, naslovnica pravog letka, „Izašao je novi / Konzumov katalog“, „Vrijedi od 7. 10. do 13. 10. 2026.“ | „Hej, izašao je novi Konzumov katalog.“ |
| Listanje | ~3 – 4,3 s | kamera na stranicu, pokazivač povlači letak do stranice s proizvodima | „Prelistaj ga i dodaj proizvod na svoju listu dodirom na letak.“ |
| Tri dodira | 4,7 / 6,2 / 7,6 s | pokazivač dodiruje proizvod, kartica se označi (rub + kvačica), obavijest „Dodano u listu · marka naziv“, nakon prvog zum na preostala dva | zvuk dodavanja iz aplikacije **na kadru dodira** |
| Lista | ~8,6 – 11,3 s | zaslon Liste: tri retka i „Ukupno“, prsten oko zbroja | — |
| Poziv | od 11,3 s | zelena kartica „Preuzmi Listo“, lista, gumb, „Poveznica do aplikacije je u komentaru.“ | „Preuzmi Listo.“ + „Poveznica do aplikacije je u komentaru.“ |

Titlovi (tamna traka na dnu, kao u videu A) prate rečenice glasa; bez glasa isti tekst stoji kao titl i video je
isti oblik. Zvuk dodavanja je `app/assets/sounds/added.wav` iz aplikacije (`resources/catalog-video/sounds/`).

**Miks** (`CatalogVideoRenderer::soundtrackGraph`): glazba brenda ispod glasa i dodatno stišana dok glas govori
(`sidechaincompress`), svaka rečenica na svom početku s glasnoćom koju je `NarrationClip` izračunao, zvuk dodavanja
−4 dB na svaki dodir. Vrhovi se prvo skrate na −3,5 dBFS (inače miks s vrškom tik ispod 0 dBFS ne može do −14 LUFS
a da ne probije strop), zatim `loudnorm` u dva prolaza (izmjeri pa jedan linearni korak) prema **−14 LUFS** i
vršku **−1,5 dBTP** (strop je 0,5 dB niži jer AAC dodaje desetinku-dvije). Izmjereno na gotovom videu s pravim
glasom (ffmpeg `ebur128`): **−14,4 LUFS, −1,7 dBTP**, 16,4 s; testovi traže −14 ± 0,8 LUFS i vršak ≤ −1,5 dBTP.

## Odabir tri proizvoda (Listo, `server/api/catalogDemo.ts`)

Podatkovno, bez modela. Prva stranica koja ima tri dostojna proizvoda, među njima:

- **svakodnevna hrana** (grupe iz `shoppingRelevance`: meso/riba, pekarski, mliječno/jaja, voće/povrće, osnovne
  namirnice; ne uređaji, posuđe, kućanstvo, njega), bez dodataka prehrani i pića;
- `confidence` ≥ 0,9, bez zastavica, `offer_kind = price`, cijena od 0,20 do 15 €, čist naziv (3–32 znaka, slova i
  brojke, ne samo velika slova, bez „ili“ koje spaja dva proizvoda), marka + naziv staju u obavijest;
- **jedna ponuda po kartici** (kartica s više ponuda u aplikaciji otvara izbor, ne dodaje);
- **dodir koji aplikacija prihvaća**: na sredinu kartice `tapOutcome` mora dati „dodaj“, ne popis stranice
  (`catalogTap.ts` je preslika `app/lib/tapMath.ts` + `density.ts`, test ih uspoređuje na 2400 nasumičnih dodira);
  gusta stranica i premala kartica (< 44 pt na 360 pt širine) otpadaju;
- bez duplikata naziva; prednost različitim grupama i markama, zatim `priority` (svakodnevna kupnja pa popust).

Zbroj na listi je zbroj stvarnih `price_cents`; hub odbija blok u kojem `total_cents` to nije.
Letak bez tri dostojna proizvoda ne ide u feed. Na 20 javnih letaka (3. 10. 2026.) izbor je našao tri za svaki
glavni letak (Konzum, Spar, Kaufland, Lidl, Plodine, Müller); tematske brošure (< 150 proizvoda) izvor ionako
izostavlja.

## Zaslon aplikacije je kopija, ne snimka

Stranica letka je prava slika s API-ja; sve ostalo (zaslon letka s gornjom trakom, oznaka kartice, bljesak,
obavijest, zaslon Liste, donja navigacija) je HTML/CSS (`resources/catalog-video/`) u mjerama iz koda aplikacije
(`app/katalog/[id].tsx`, `components/ListCard.tsx`, `components/Toast.tsx`, `lib/theme.ts`; fontovi Fraunces 700,
Figtree 400–800, Ionicons). Red na listi je **novije prvo** (`order by position, added_at desc`), kao u aplikaciji.

Provjera prema stvarnim snimkama zaslona (`listo-stvarni-letak/work/native-list-loaded.png`, `raw-12.0.jpg`):
isti proizvodi u istom poretku, snimljeni u 1206×2622, razlika po slikama je unutar 1–2 pt po rasporedu i
nema razlike u sadržaju („Ukupno 7,07 €“ u oba). Razlike koje ostaju i koje sam namjerno ostavio:

- **Status traka** je nacrtana (SF Pro nije dostupan): vrijeme „09:41“ je Figtree, ikone su jednostavne.
- **Obavijest** je u kadru pri dnu ploče, ne pri dnu zaslona telefona: kamera zumira na proizvod, a obavijest bi
  inače ostala izvan izreza. Sam element je isti (`Toast.tsx`), animacija ista (220 ms unutra, 1500 ms, 260 ms van).
- **Prijelaz na listu** je križni prijelaz, ne navigacija (put natrag → Liste nije snimljen).
- Aplikacija pri dodavanju traži **prijavu** novog korisnika (`tapOutcome: signIn`); video pokazuje ono što se
  događa nakon nje. Ne obećava se ništa više („Prelistaj ga i dodaj proizvod…“ stoji u glasu i tekstu).

## Poveznica, komentar i mjerenje

Jedna poveznica po videu i kanalu: `https://uselisto.com/app?utm_source=<tiktok|instagram|facebook>&utm_medium=social&utm_campaign=katalog-<lanac>-<valid_from>`
(`App\Catalog\TrackedLink`; adresu i kampanju daje izvor u `raw.demo.app_url` / `campaign`). `/app` otvara pravu
trgovinu po uređaju i prenosi izvor: Play `referrer`, Apple `ct`. `utm_source` je obavezan (bez njega se oznaka ne
čita). Apple reže `ct` na 40 znakova (`<izvor>-<kampanja>-poveznica`); datum ostaje u njemu za lance do 9 slova
(test), duža imena lanaca treba provjeriti u App Store Connectu.

Po varijanti se sprema: `link_url` (poveznica) i `settings.tracking` (`url`, `source`, `medium`, `campaign`), da se
registracija s `source/campaign` može pripisati objavi. Android: Listo bilježi `prijava` s `source/campaign/medium`
(Play referrer); iOS se izvor vidi samo u App Store Connectu.

- **Facebook stranica i Instagram:** poveznica je **prvi komentar** (`settings.first_comment`:
  `FacebookPagePublisher`, `InstagramPublisher`): „👉 Preuzmi Listo: <poveznica>“. Na Facebooku je klikabilna i ima
  oznake kanala. Na Instagramu i TikToku se ne može dodirnuti, pa je tamo komentar **„👉 Preuzmi Listo: uselisto.com/app“**
  (Marijan, 7. 10. 2026.: prva objava imala je cijelu poveznicu s `utm` upitom, a nitko je ne bi prepisao). Izbor je
  `catalog_video.link_typed` po kanalu; kad je uključen, varijanta ne sprema `settings.tracking` jer komentar ne nosi oznaku.
  **Cijena:** gola `/app` Listo bilježi bez kanala (`utm_source=uselisto.com`, mjesto „poveznica“), pa se TikTok i Instagram
  više ne razlikuju ni po videu. Ako to zatreba, Listo već ima jednako kratke `uselisto.com/tiktok` i `uselisto.com/instagram`
  (`server/pages/entryLinks.ts`) koje nose izvor, ali vode na naslovnicu, ne izravno u trgovinu (jedan dodir više).
  Prva živa objava (6. 10. 2026.): Facebook ostavio komentar (`fb.first_comment` 200); Instagram ne, jer token nema
  `instagram_manage_comments` (`ig.first_comment` 400, `(#10)`). Dozvola je dodana u Meta dashboardu (use case i Login
  Configuration) i u `config/meta.php`; nakon ponovnog povezivanja Instagram i TikTok komentar su na idućoj objavi bili
  vidljivi (potvrdio Marijan).
- **TikTok:** komentar ostavlja `LeaveTikTokCommentJob` kroz API čim aplikacija dobije dozvolu, a do tada ga adminu daje kao tekst za lijepljenje (niže).

### TikTok: prvi komentar (odluka 3. 10. 2026.: komentirati preko API-ja, uz dozvolu)

Video na TikToku kaže „u komentaru“ kao i na ostalim kanalima, pa TikTok varijanta nosi `settings.first_comment` s
istom poveznicom (`utm_source=tiktok`). Komentar ostavlja `LeaveTikTokCommentJob`, tri minute nakon objave (TikTok
objavljeni video učini javnim do tri minute kasnije), u tri poziva iz dokumentacije:

1. `GET /business/publish/status/` (`business_id`, `publish_id` = `share_id` objave) → `post_ids[0]` kad je
   `PUBLISH_COMPLETE`; dok nije, job čeka i pita ponovno (do osam puta, ~45 min), `FAILED` znači da nema na što komentirati;
2. `POST /business/comment/create/` (`business_id`, `video_id` = taj id, `text` ≤ 1200 znakova) → `comment_id`;
3. `GET /business/comment/list/` s tim `comment_ids` → `status`: **`HIDDEN` se ne računa kao objavljeno** (TikTok
   sakriva komentar koji drži za spam i ništa ne javlja; dokumentacija izričito upozorava na gotovo iste komentare).

Ishod je u `settings.first_comment_status`: `posted`, ili `manual` (+ `first_comment_reason`). **Ako komentar ne uspije,
admin dobije mail s točnim tekstom** (`TikTokCommentNeeded`: nema dozvole, objava nije postala javna, komentar sakriven,
TikTok odbio): tekst zalijepi ručno i obećanje videa vrijedi. Isti mail stiže dok je komentiranje isključeno
(`TIKTOK_BUSINESS_COMMENTS=false`, zadano), pa se može pustiti prve videe prije nego dozvola stigne. Id objave se sprema
u `settings.tiktok_video_id`. Poveznica u TikTok komentaru nije klikabilna; to je znana granica.

**Što treba napraviti (Marijan; ja portal i produkciju ne diram):**

1. **Portal → My Apps → Croobo Social Hub → permissions:** dodati dozvole za komentare. U odgovoru TikToka
   (`/tt_user/token_info/get/`) zovu se `comment.list.manage` (upravljanje, pa i stvaranje komentara) i `comment.list`
   (čitanje, treba za provjeru da komentar nije skriven). Koja točno treba za `comment/create` ne piše uz endpoint, pa ih
   treba tražiti obje.
2. **Moguća nova prijava:** dokumentacija kaže da se od 20. 3. 2026. „Accounts API Access Application Form“ mora ispuniti i
   prije **povećanja dozvola koje uključuje „TikTok accounts“**. Komentari su u toj grupi, pa je vjerojatno da TikTok traži
   formu (i obrazloženje „zašto API a ne ručno“) ponovno. Nije potvrđeno dok ne probaš. Ono što formi treba: komentar je
   hubova vlastita poveznica na vlastitom videu, jedan po objavi (ne masovno, ne tuđim videima).
3. Kad dozvola prođe: **nova autorizacijska adresa** iz portala u `TIKTOK_BUSINESS_AUTHORIZE_URL` (sadrži opseg, kao u
   `docs/tiktok-business-api.md` § 11), `php artisan config:cache`, i **svaki TikTok račun ponovno povezati**
   (stari token nema novi opseg; provjera: `scope` u `/tt_user/token_info/get/`).
4. `TIKTOK_BUSINESS_COMMENTS=true`. Prvi komentar pogledaj u aplikaciji: je li vidljiv i ne sakriven.

Dok se to ne dogodi video radi kao i dosad, a ručni komentar je jedini korak koji ostaje na čovjeku.

## Oznaka AI-generiranog sadržaja

Glas je sintetički, pa se oznaka šalje gdje ju API ima (`PostVariant::aiGenerated()`: video sa sintetičkim glasom,
osim ako kanal kaže `ai_generated = false`):

| Mreža | Parametar | Izvor |
|---|---|---|
| TikTok Business API | `post_info.is_ai_generated` (oznaka „Creator labeled as AI-generated“, ne može se naknadno maknuti) | dokumentacija `business/video/publish/` |
| TikTok Content Posting API (developer track) | `post_info.is_aigc` | dokumentacija Direct Post |
| Instagram | `is_ai_generated=true` pri izradi spremnika (od 22. 6. 2026.) | Instagram Platform changelog |
| Facebook Reels | **nema parametra** u dokumentaciji `video_reels` | — |

Instagram: ako pripnuta verzija Grapha (`META_GRAPH_VERSION`, sad `v23.0`) parametar ne poznaje, Reel se ne
zadržava: pokuša se bez njega i u log ide `hub.ig.ai_label_rejected`. **Ni jedno od tri slanja nije isprobano na
živom računu.**

## Uključivanje (radi Marijan u panelu; produkcijsku konfiguraciju ne diram)

1. Deploy (migracija `2026_10_03_000001`: `auto_publish_rules.requires_approval`; postojeća pravila ostaju kakva su
   bila — objavljuju bez odobrenja).
2. **Brendovi → Listo → Voice-over:** glas `ZLYZToA7aDsMbHwM9AOr`, model `eleven_v4`. Izgovor imena („letak“…) ide
   u popis izgovora (zadatak „Hub: izgovor preko ElevenLabs IPA rječnika“); retci ovog videa prolaze kroz isti
   `Narrator` kao svi ostali, pa ga preuzimaju sami.
3. **Brendovi → Listo → Termini objave:** za 18:30 treba prozor koji počinje u 18:30 (npr. 18:30–21:30); automatika
   uzima prvi termin iza stvaranja nacrta.
4. **Izvori → novi izvor** `listo-katalozi`: Social Feed v1, isti URL i ključ kao `listo`, „Napredno → Postavke“:
   `query.kind` = `catalog` (po želji `query.min_products`, `query.max_validity_days`). „Testiraj vezu“ mora vratiti stavke
   vrste Katalog.
5. **Automatska objava** na tom izvoru, po jedno pravilo za TikTok, Instagram i Facebook stranicu, **format Video**,
   „Najviše dnevno“ = 1, **Traži odobrenje uključeno** (zadano). Pravila 1, 2 i 6 (`enabled=0` od 30. 9.) ostaju
   isključena dok Marijan ne kaže drukčije; dnevni limit brenda (`daily_post_limit`) vrijedi i ovdje.
6. Kad su prvi videi pregledani: isključi *Traži odobrenje* na pravilima — potpuna automatika.

Pregledi („Top 3 akcije“, `hub:auto-digest`) ne čitaju prekidač: serije su zasebna automatika.

Stavka je „vijest“ tri dana (`hub.max_age_days.catalog`): prva sinkronizacija izvora koji već drži mjesec dana letaka
ne stavlja u red videe za sve njih. Letak mora vrijediti još tri dana nakon objave (`hub.min_days_valid.catalog`).
Osvježen letak (nova verzija s novim `id`-jem) ima identitet prve verzije, pa nije nova objava.

## Naredbe

```bash
./vendor/bin/sail artisan hub:catalog-stills --fixture=tests/Fixtures/catalog-feed-konzum-2026-10-07.json --at=1.0,4.7,9.0
./vendor/bin/sail artisan hub:catalog-draft --sync        # nacrt za tri kanala, render odmah (faza 1), čeka odobrenje
./vendor/bin/sail artisan hub:catalog-draft 123 --approve --at="2026-10-07 18:30"
./vendor/bin/sail artisan hub:catalog-export 45 --platform=tiktok   # faza 4: video + oglasna poveznica + tekst
```

`hub:catalog-stills` crta bilo koju sekundu scene kao PNG bez objave ičega (scena je funkcija vremena), pa se
„je li obavijest ispravna na 6,2 s“ vidi jednom naredbom. Fixture-i u `tests/Fixtures/` su prave odgovore Listova
feeda za letke od 3. 10. 2026. (Konzum 30. 9. i 7. 10., Lidl, Spar).

## Faze

1. **Ručno za Konzum 7.–13. 10.** — gotovo i isprobano lokalno: `hub:catalog-draft --sync` (tri kanala, s glasom Luka,
   ~35 s). Nacrt čeka odobrenje; ništa nije objavljeno.
2. **Automatski okidač i pravilo** — gotovo: izvor + pravila (gore), `requires_approval`, dob vijesti, termin.
3. **Ostali lanci** — gotovo bez koda: pridjev je u Listovoj mapi (`CATALOG_PHRASE`: Konzum, Lidl, Spar, Kaufland,
   Plodine, Bipa, Müller, Eurospin, Tommy, dm), lanac bez zapisa dobiva „katalog trgovine X“; logotip dolazi u
   `images[role=logo]`. Lanac koji nije u mapi: dodati red u `catalogDemo.ts`.
4. **Izvoz „spreman za plaćeno“** — gotovo: `hub:catalog-export` (video, naslovnica, `utm_medium=paid` poveznica, tekst,
   `export.json`). Ništa se ne kupuje ni ne objavljuje.

## Što nije provjereno

- **Zvuk i naglasak**: ne mogu slušati. Provjereno je samo mjerenjem (glasnoća, vršak, početak zvuka dodavanja na
  kadru dodira, trajanje rečenica) i da je glas stvarno izgovorio sva četiri retka. „letak“, „Listo“, „Konzumov“ su riječi
  koje glas može krivo naglasiti; to odlučuje Marijanovo uho.
- Slanje na **TikTok / Instagram / Facebook** (i oznaka AI) na živim računima; TikTok Business ostaje prema `docs/tiktok-business-api.md` § 8.
- **Izgled na telefonu**: kadar je 1080×1920 po predlošku videa A (naslov, ploča, titlovi na dnu); sigurne zone
  TikToka/Reelsa (desnih ~130 px, donjih ~400 px) nisam mijenjao; titlovi na y = 1590 su djelomično pod opisom.
- Zaslon **Liste i letka** su kopija aplikacije na dan 3. 10. 2026.; promjena aplikacije traži osvježenje
  `resources/catalog-video/scene.*`.

## Gdje je u kodu

```
app/Catalog/CatalogDemo           raw.demo: strogo čitanje, zbroj mora biti zbroj
app/Catalog/CatalogVideoPlan      sva vremena videa (kadar po kadar), elastično prema glasu
app/Catalog/CatalogCopy           sve riječi: glas, titlovi, naslov, tekst objave, prvi komentar
app/Catalog/TrackedLink           poveznica po kanalu (organska i plaćena)
app/Catalog/CatalogVariant        što kanal nacrta nosi: poveznica, komentar, format
app/Catalog/CatalogSceneBundle    mapa za Chromium: scena, fontovi, slike, data.js
app/Catalog/PaidExport            izvoz za plaćeno
app/Rendering/CatalogVideo/*      kadrovi (Chromium), miks i kodiranje (ffmpeg), poster
resources/catalog-video/          scena (scene.html/css/js), fontovi, zvuk dodavanja
resources/js/catalog-frames.mjs   Node: za svaki t pozove renderAt(t) i snimi JPEG
app/Jobs/RenderCatalogVideoJob    glas nikad ne ruši render; razlog ide na video, jedan mail adminu
config/catalog_video.php          boje, gdje je poveznica po kanalu, zvuk dodavanja, glasnoća
```
