# TikTok Business API (Organic / Accounts API) — postava i migracija

Ovo je **Staza B** iz `docs/tiktok.md` („Zid 0"): prelazak s odbijenog developer tracka
(`developers.tiktok.com`, `open.tiktokapis.com`) na **TikTok API for Business**
(`business-api.tiktok.com`), gdje je objavljivanje na vlastite brendove dopušten use-case.

Developer račun: `info@croobo.hr`, portal `https://business-api.tiktok.com/portal/apps`.

## Zašto ovaj track uopće rješava problem

Endpoint se doslovno zove **„Publish a public video post to an owned TikTok account"**. Nema
SELF_ONLY zida ni audita koji nikad ne može proći — limit je 6 objava/min i **15 dnevno po računu**,
što je daleko iznad naših potreba.

## 1. Aplikacija u portalu

**My Apps → Create App.** Vrijednosti koje treba upisati:

| Polje | Vrijednost |
|---|---|
| App name | `Social Hub` (ili `Croobo Social Hub`) |
| Description | Opisati kao alat kojim **brendovi objavljuju vlastiti organski sadržaj** na svoje račune — planiranje, renderiranje i objava vlastitih videa i slika. To je ovdje prihvatljivo, za razliku od developer tracka. |
| TikTok account holder redirect URL | `https://hub.uselisto.com/tiktok/business/callback/` |

### Redirect URL ima stroža pravila nego developer track

Ovdje se najlakše zaglavi — postojeći `https://hub.uselisto.com/tiktok/callback` **ne prolazi**:

- mora završavati kosom crtom `/`,
- bez query parametara (`?…`) i bez sidra (`#…`),
- obavezno `https://`, **bez porta**,
- 10–512 znakova.

Sve što bismo inače stavili u query string ide u **`state`** parametar autorizacijskog URL-a, kao
JSON (npr. `&state={"account":"12"}`). Hub tako prenosi koji se račun povezuje.

Aplikacija smije imati do 10 redirect URL-ova, ali je **jedan aktivan** u isto vrijeme, i TikTok za
svaki aktivni sam **generira autorizacijski URL** — ne slaže se ručno kao kod Login Kita. Taj URL
treba prepisati u `.env`.

## 2. Dozvole (scopes) koje treba tražiti

Iz odgovora `/tt_user/oauth2/token/` vidljiv je cijeli katalog; hubu trebaju:

| Scope | Čemu |
|---|---|
| `video.publish` | izravna javna objava |
| `video.upload` | nacrt u inbox (zadržavamo za trending zvuk) |
| `video.list` | popis objava za mjerenje |
| `video.insights` | brojke po objavi (zamjena za `video/query`) |
| `user.info.basic`, `user.info.profile` | ime i avatar računa u panelu |
| `biz.creator.info` | dopuštene postavke računa prije objave |

## 3. Verifikacija URL-a medija (URL property)

Od 11/2023 `video_url` mora biti unutar **verificiranog URL propertyja**, inače objava ne prolazi.
URL mora biti `https`, **bez redirecta**, i dostupan cijelo vrijeme preuzimanja (timeout je sat
vremena od početka).

**Verifikacija je po aplikaciji, ne po računu.** `/business/property/add|verify|list/` nose
`app_id` + `secret` i nikakav token, pa se radi jednom, prije nego što se spoji ijedan brend.

Postoje dvije vrste, a hub koristi drugu:

- *domena* (tip 1) — TXT zapis u DNS-u, pokriva i poddomene; traži čovjeka i pristup DNS-u,
- ***URL prefiks*** (tip 2) — datoteka s potpisom na samom prefiksu. Hub je može zapisati sam.

```
./vendor/bin/sail artisan hub:tiktok-url-property      # lokalno odbija: medij nije na https
php artisan hub:tiktok-url-property                    # u produkciji
```

Naredba verificira `https://hub.uselisto.com/storage/media/` — mapu u koju `ImageRenderer` i
`VideoRenderer` pišu — a ne cijelu domenu, pa se kao „naše" računa samo ono što je hub renderirao.
Redom: `list` → (ako ga nema) `add` → potpis u `media/<file_name>` na javnom disku → provjera da se
datoteka stvarno poslužuje (TikTok ne slijedi redirecte i ne kaže zašto je pao) → `verify`.
Ponovno pokretanje je bezopasno: verificirani prefiks ostavlja na miru, a neuspjeli ponovno
provjerava s istim potpisom. **Datoteku s potpisom ne brisati** — TikTok je smije ponovno provjeriti.

> Ako se promijeni `APP_URL` ili disk za medije, prefiks je drugi i naredbu treba pokrenuti ponovno.

## 4. Autorizacija i tokeni

```
POST https://business-api.tiktok.com/open_api/v1.3/tt_user/oauth2/token/
     { client_id, client_secret, grant_type: "authorization_code", auth_code, redirect_uri }

POST …/tt_user/oauth2/refresh_token/
     { client_id, client_secret, grant_type: "refresh_token", refresh_token }

POST …/tt_user/oauth2/revoke/
     { client_id, client_secret, access_token }
```

- portal generira **standardni TikTokov** `https://www.tiktok.com/v2/auth/authorize?client_key=…&response_type=code`,
  pa se u callback vraća **`?code=…&state=…`**; ta se vrijednost u token poziv šalje kao `auth_code`.
  (Dokumentacija samo kaže „authorization code … as an added query parameter"; kontroler zato prima
  i `auth_code`.)
- `auth_code` vrijedi **10 minuta** i troši se jednom,
- ako je račun već autorizirao aplikaciju s istim dozvolama, TikTok preskače ekran pristanka; za
  ponovno traženje (npr. nakon promjene opsega) na autorizacijski URL dodati `&disable_auto_auth=1`,
- `redirect_uri` se **mora** poslati i mora biti identičan onom u aplikaciji,
- pristupni token vrijedi **1 dan**, refresh token **1 godinu** — ista dinamika kao dosad, pa
  `hub:refresh-tiktok-tokens` (satno) ostaje kakav jest,
- odgovor nosi **`open_id`**, koji se dalje šalje kao **`business_id`** pri objavi. Spremiti ga.

## 5. Objava

```
POST https://business-api.tiktok.com/open_api/v1.3/business/video/publish/
Header: Access-Token: <token>
{
  "business_id": "<open_id>",
  "video_url": "https://hub.uselisto.com/…/video.mp4",
  "post_info": {
    "caption": "…",
    "is_brand_organic": true,
    "is_branded_content": false,
    "disable_comment": false,
    "disable_duet": false,
    "disable_stitch": false
  }
}
```

- **`is_brand_organic` i `is_branded_content` su obavezni.** Hub objavljuje vlastite ponude brenda,
  dakle `is_brand_organic: true` → objava dobije oznaku „Promotional content".
  `is_branded_content` je za plaćena partnerstva i gasi `is_brand_organic` ako su oba `true`.
- `post_info` je obavezan i kad je prazan (`{}`).
- `upload_to_draft: true` šalje u inbox umjesto objave (traži `video.upload`); tada se **svi ostali
  `post_info` parametri ignoriraju**.
- Dopuštene vrijednosti za komentare/duet/stitch po računu daje `/business/video/settings/`
  (`comment_disabled`, `duet_disabled`, `stitch_disabled`, `max_video_post_duration_sec`) — isti
  obrazac kao `creator_info/query` dosad: pitaj pa se prilagodi.
- Umjesto pollinga postoje **webhooks** za događaje objave (`/business/webhook/update/`).

### Ograničenja videa

| Pravilo | Vrijednost |
|---|---|
| Format | `.mp4`, `.mov` ili `.webm` |
| Veličina | ≤ 1 GB |
| Trajanje | 3–600 s (gornja granica po računu iz `/business/video/settings/`) |
| Rezolucija | min 360 px po strani |
| Frame rate | **23–60 FPS** |

`VideoRenderer` (1080×1920, H.264/AAC, `FPS = 30`) **već sve zadovoljava** — video se ne mora
mijenjati, mijenja se samo tko ga i kako objavljuje.

## 6. Zvuk: ovdje postoji `music_sound_id`

Za razliku od developer tracka, Business API prima komercijalnu glazbu:

```json
"music_sound_info": { "music_sound_id": "…", "music_sound_volume": 50,
                      "music_sound_start": 0, "music_sound_end": 15000 },
"video_original_sound_volume": 0
```

ID-evi dolaze iz **Commercial Music Library** preko `/discovery/cml/trending_list/`. To obara
pravilo iz `CLAUDE.md` („API ne može dodati TikTokov zvuk, nema `music_id`") — ono vrijedi samo za
developer track. Ako ovo prođe, inbox put prestaje biti jedini način da objava dobije TikTokov zvuk.

Napomena: default glasnoća u oba polja je `0`, dok je u aplikaciji 50 — treba je postaviti
eksplicitno, inače je objava nijema.

## 7. Stanje u kodu

Oba tracka žive jedan pokraj drugoga, a bira ih **`TIKTOK_API=developer|business`**
(`config/tiktok.php`). Prekidač je namjerno privremen: developer track ostaje dok Business ne
proradi, jer je do tada jedini način da se TikTok uopće isproba (sandbox, inbox).

Napisano i pokriveno testovima:

| Datoteka | Što radi |
|---|---|
| `app/Publishing/TikTok/TikTokTokens.php` | ugovor koji dijele oba tracka, pa connect i `hub:refresh-tiktok-tokens` ne znaju koji je aktivan |
| `app/Publishing/TikTok/Business/TikTokBusinessOAuth.php` | `tt_user/oauth2/token|refresh_token|revoke` |
| `app/Publishing/TikTok/Business/TikTokBusinessClient.php` | `Access-Token` zaglavlje, `code`/`message`/`data` omotnica, mapiranje grešaka |
| `app/Publishing/TikTok/Business/TikTokBusinessPublisher.php` | `business/video/settings/` pa `business/video/publish/`, uz `upload_to_draft` za inbox |
| `app/Http/Controllers/TikTok/TikTokBusinessOAuthController.php` | povezivanje računa, sprema `open_id` |
| `routes/web.php` | `/tiktok/business/connect/{brand}` i `/tiktok/business/callback/` |
| `app/Publishing/TikTok/Business/TikTokUrlProperties.php` + `hub:tiktok-url-property` | verifikacija `storage/media/` prefiksa (§ 3) |
| `ListSocialAccounts` → *Poveži TikTok* | vodi na Business tok kad je on driver |
| `PublisherRegistry`, `AppServiceProvider` | biraju implementaciju po `tiktok.driver` |

Render, predlošci i raspoređivanje se **ne mijenjaju** — video koji hub već radi zadovoljava sva
ograničenja ovog tracka.

## 8. Što ostaje nakon prvog spojenog računa

Ovo se ne da dovršiti naslijepo jer traži odgovore pravog API-ja:

- **Permalink.** `business/video/publish/` vraća samo `share_id`; prava poveznica dolazi kroz
  webhook ili `business/publish/status/`, a njihovi statusi nisu javno dokumentirani. Zato publisher
  zasad sprema `share_id` i ostavlja permalink prazan — objava je javna, hub samo još nema link.
- **Mjerenje.** `App\Metrics\TikTokMetrics` i dalje gađa developer `video/query`; na ovom tracku to
  je `business/video/list/` + `video.insights`.
- **Ime računa.** Connect pokušava `business/get/` za korisničko ime i tiho pada na `open_id` ako
  endpoint nije takav — provjeriti čim se račun poveže.
- **Foto objave (carousel).** `/business/photo/publish/` postoji (vidljiv uz opseg *Photo publish*),
  ali publisher ga još ne zove — odbija sliku i carousel i upućuje na inbox. Napisati kad se vidi
  pravi odgovor.
- **Dodijeljeni opsezi.** Portalov URL traži `user.info.basic, user.info.username,
  user.info.stats, user.info.profile, user.account.type, user.insights, video.publish,
  video.upload, video.list, video.insights` — **bez `biz.creator.info`**. Provjeriti prolazi li
  `/business/video/settings/` s ovim opsezima; notifikacija nakon spajanja ispisuje što je TikTok
  stvarno dodijelio.

## 9. Pristup: Accounts API traži zasebnu prijavu, i to PRVU

Provjereno u portalu 22. 09. 2026. Accounts API **jest** ponuđen pri kreiranju aplikacije, ali opseg
„TikTok accounts" nosi upozorenje:

> „To request the Accounts API scope, you must fill out the **Accounts API Access Application Form**.
> Failure to do so may result in your Accounts and Business Messaging API approvals being rejected."

Sama forma (Lark, `bytedance.sg.larkoffice.com/share/base/form/shrlgu4WEvtSXpEDLcCw56u4Rfc`) na vrhu
kaže da se ispunjava **prije** slanja prijave aplikacije. **Redoslijed je dakle: forma → pa tek onda
Create App.** Šalje se jednom po developer profilu i use-caseu, čak i za više aplikacija.

> **Stanje 23. 09. 2026.: aplikacija `Croobo Social Hub` je odobrena** (App ID
> `7688584683054956564`). Puštanje u produkciju: § 11.
> Snimka je napravljena iz demo podataka koji su nakon toga obrisani; skripte za novi prolaz stoje u
> `storage/app/` (`tiktok-demo-seed.php`, `record-walkthrough.mjs`, `build-walkthrough.sh`).

### Što forma traži (11 pitanja)

| # | Pitanje | Naš odgovor |
|---|---|---|
| 1 | Business Name (pravno ime iz registracije) | **Nije d.o.o.** — `croobo.hr` se sam predstavlja kao „Croobo — obrt za promidžbu", dakle obrt. Mora se poklapati s dokumentom iz 6. pitanja |
| 2 | Podudara li se s Company Name u developer profilu | Da (profil kaže „Croobo") |
| 3 | App Name | `Croobo Social Hub` |
| 4 | Email (mora biti isti kao pri registraciji profila) | `info@croobo.hr` |
| 5 | Website | `https://croobo.hr`, `https://hub.uselisto.com` |
| 6 | Business Verification | dokument **ili** Business Center ID s verificiranim podacima |
| 7 | Use Case (detaljno, uz obrazloženje **zašto API a ne ručno**) | nacrt niže |
| 8 | **Screen Recordings** | snimka zaslona huba; dopušten je i prototip |
| 9 | Broj računa koji će autorizirati aplikaciju | Less Than 10 |
| 10 | Developer Account Type | Direct Advertiser |
| 11 | Usage Acknowledgment and Revocation Consent | Agree |

Pitanja 6, 8 i 11 traže čovjeka: dokumenti tvrtke, snimka zaslona i pristanak.

### Nacrt odgovora na 7. pitanje

> Croobo owns three consumer websites and the TikTok account of each brand: studentski-poslovi.hr
> and radim.hr (job boards) and uselisto.com (retail offers). We publish only to accounts we own.
>
> Our websites produce new content continuously — dozens of new job listings and retail offers every
> day, each with its own title, price or salary, employer or retailer, and expiry date. Social Hub
> reads them from our own JSON feed, renders each into a vertical video or photo post from our brand
> templates, and publishes it to the matching brand account within that brand's posting windows.
>
> Doing this natively is not a matter of convenience but of correctness. Posting by hand would mean
> re-typing every price and deadline into the TikTok app, which is exactly where mistakes reach the
> public: an expired offer or a wrong price is a consumer-protection problem for us. The hub
> validates every figure in the caption against the source record before publishing, checks that the
> linked offer is still live, and stops the post if it is not. It also spaces posts across each
> brand's allowed hours and enforces a daily cap per channel. None of that is expressible by
> uploading files manually, and it is the reason we integrate the API rather than use the TikTok app.
>
> We use the scopes as follows: `/business/video/publish/` and `/business/photo/publish/` to publish
> our own content, `/business/video/settings/` to respect what each account allows before posting,
> `upload_to_draft` when a person wants to finish a post in the app, and `/business/video/list/` with
> account insights to measure which of our own offers performed best. We do not read or process data
> belonging to other TikTok users, and we do not display TikTok content on our websites.

## 10. Vrijednosti za Create App (kad forma prođe)

| Polje | Vrijednost |
|---|---|
| App name | `Croobo Social Hub` |
| App description | vidi § 9 nacrt, skraćeno na 500 znakova |
| **TikTok account holder redirect URL** | `https://hub.uselisto.com/tiktok/business/callback/` |
| Advertiser redirect URL | `https://croobo.hr/` |
| App logo | 512×512 PNG, **obavezno** |
| Scope → TikTok accounts | Account user (basic info + insights), Get account media, Account post content (Video publish, Photo publish, Video Upload) |

*Account comment* se **ne** traži — hub ne dira komentare, a prijava koja traži više nego što koristi
daje recenzentu razloga za pitanja.

### Dvije redirect adrese, samo jedna je naša

Dijalog ima **dva** polja i lako ih je zamijeniti:

- **TikTok account holder redirect URL** (dno forme) je ono što hub stvarno koristi — na nju se vraća
  vlasnik TikTok računa nakon `tt_user` autorizacije, i za nju TikTok generira autorizacijski URL.
  Ovdje vrijede stroga pravila iz § 1 (završna `/`, bez querya, bez porta).
- **Advertiser redirect URL** (vrh forme) je callback za Business Center / Advertiser / TikTok Creator
  Marketplace tok, koji hub ne koristi. TikTokov vlastiti opis kaže da tu ide „publicly reachable
  website" i preporučuje službenu stranicu tvrtke — zato `https://croobo.hr/`, ne naš callback.

Logo je obavezan (512×512 JPG/PNG). Uzet je znak sa `croobo.hr` (tamnoplavi zaobljeni kvadrat, plavo
„C") i prerenderiran na 512 px: `storage/app/appicon/make-icon.php` → `croobo-512.png`.

> Portal je jednom pukao („Application error: a client-side exception has occurred") pri širenju
> stabla opsega i **izgubio sve upisano**. Stablo se širi jedan po jedan čvor, uz pauzu; polja se
> popunjavaju tek na kraju.

### Što je popis opsega usput otkrio

Endpointi vidljivi uz „TikTok accounts" potvrđuju tri stvari koje su dosad bile otvorene:

- **`/business/photo/publish/` postoji** — foto objave i carousel rade na ovom tracku, pa
  `TikTokBusinessPublisher` koji ih sad odbija treba dobiti pravu implementaciju.
- **`/business/publish/status/` postoji** — permalink se ipak može razriješiti pollingom, ne samo
  webhookom.
- **`/business/get/`** je stvaran, pa je pogađanje u `TikTokBusinessOAuthController::profile()` bilo
  točno.

## 11. Puštanje u produkciju

Aplikacija je odobrena 23. 09. 2026. Redoslijed je bitan — verifikacija i spajanje trebaju
produkcijski `https` URL, pa se ništa od ovoga ne da isprobati lokalno.

**1. Deploy** koda s Business trackom.

**2. `.env` na Ploiju:**

```
TIKTOK_API=business
TIKTOK_BUSINESS_APP_ID=7688584683054956564
TIKTOK_BUSINESS_APP_SECRET=            # portal → My Apps → Croobo Social Hub → Secret (oko)
TIKTOK_BUSINESS_REDIRECT_URI="${APP_URL}/tiktok/business/callback/"
TIKTOK_BUSINESS_AUTHORIZE_URL="https://www.tiktok.com/v2/auth/authorize?client_key=7688584683054956564&scope=user.info.basic%2Cuser.info.username%2Cuser.info.stats%2Cuser.info.profile%2Cuser.account.type%2Cuser.insights%2Cvideo.publish%2Cvideo.upload%2Cvideo.list%2Cvideo.insights&response_type=code&redirect_uri=https%3A%2F%2Fhub.uselisto.com%2Ftiktok%2Fbusiness%2Fcallback%2F"
```

Autorizacijski URL se **prepisuje iz portala**, ne slaže se ručno: ako se u portalu promijeni opseg ili
redirect, TikTok generira novi. `state` hub dodaje sam. Zatim `php artisan config:cache`.

**3. Verifikacija medija:** `php artisan hub:tiktok-url-property` (§ 3).

**4. Spajanje:** panel → Računi → *Poveži TikTok* → brend → prijava u TikTok račun **tog** brenda.
Notifikacija ispisuje dodijeljene opsege.

**5. Prva objava:** jedan video nacrt na novi račun, pa u `publish_logs` provjeriti odgovore
`business/video/settings/` i `business/video/publish/` — tek tada § 8.

> **Stari developer računi.** `TIKTOK_API=business` mijenja publisher i osvježavanje tokena za
> **sve** TikTok račune. Račun spojen kroz developer track (`meta.api` nije `business`) nakon
> prekidača više ne može ni objaviti ni osvježiti token — treba ga ponovno spojiti kroz *Poveži
> TikTok*.
