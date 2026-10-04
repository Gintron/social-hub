# Social Feed v1 — ugovor za povezivanje brenda s hubom

Hub ne zna što je "oglas" ni "akcija". Zna samo za **stavke** (`items`) s općim poljima.
Stranica koja želi da hub objavljuje njezin sadržaj implementira **jedan endpoint** po ovom
ugovoru; hub ga čita istim adapterom kao i sve ostale izvore. Novi brend = novi red u tablici
`sources`, bez koda u hubu.

Strojno čitljiva verzija: [`social-feed-v1.schema.json`](social-feed-v1.schema.json) (JSON Schema
2020-12). Hubova akcija **Test connection** i testovi na stranicama validiraju protiv nje.

## Zahtjev

```
GET {base_url}?since=<ISO 8601>&cursor=<opaque>&limit=<1..100>
Authorization: Bearer <token>          (ili: X-Api-Key: <key>)
Accept: application/json
```

| Parametar | Obavezan | Značenje |
|---|---|---|
| `since` | ne | Vrati samo stavke s `updated_at >= since`. Hub šalje vrijeme zadnje uspješne sinkronizacije minus 1 h (preklapanje je namjerno; idempotencija je na hubu). |
| `cursor` | ne | Neprozirni kursor iz prethodnog `next_cursor`. |
| `limit` | ne | Zadano 50, najviše 100. |

Stranica smije primati i **vlastite filtere** izvan ugovora (listo tako ima `country` i
`min_discount`). Hub ih šalje na svakom zahtjevu ako ih upišeš u postavke izvora kao ključeve s
prefiksom `query.`, npr. `query.country` = `hr`. Ugovorni parametri (`since`, `cursor`, `limit`)
uvijek imaju prednost. Filteri upisani izravno u `base_url` također se čuvaju, ali postavke su
preglednije i vide se u panelu.

Bez filtera veliki katalog probije granicu od 50 stranica po sinkronizaciji i hub odustane s
greškom — to je zaštita, ne kvar: znak je da izvor treba suziti.

Autentikacija je obavezna. Endpoint mora biti iza rate-limita (npr. 60/min). Bez tokena → `401`,
kriva ovlast → `403`.

## Odgovor

```json
{
  "version": "1",
  "brand": "studentski-poslovi",
  "items": [
    {
      "id": "job:12345",
      "kind": "job",
      "title": "Konobar/ica",
      "subtitle": "Hotel Adriatic d.o.o.",
      "body_text": "• Posluživanje gostiju\n• Rad u smjenama",
      "facts": [
        { "label": "LOKACIJA", "value": "SPLIT" },
        { "label": "SATNICA", "value": "7,00 – 8,00 €/H" }
      ],
      "badges": ["Sezonski posao", "Smještaj", "Obrok"],
      "price": null,
      "cta": { "label": "Prijavi se", "url": "https://studentski-poslovi.hr/posao/konobar-ica-12345#apply" },
      "url": "https://studentski-poslovi.hr/posao/konobar-ica-12345",
      "images": [
        { "url": "https://studentski-poslovi.hr/images/abc.webp", "role": "primary" },
        { "url": "https://studentski-poslovi.hr/storage/12/logo.png", "role": "logo" }
      ],
      "priority": 3,
      "tags": ["konobar", "split", "sezona"],
      "published_at": "2026-09-05T07:10:00Z",
      "expires_at": "2026-10-05T00:00:00Z",
      "updated_at": "2026-09-05T07:10:00Z",
      "raw": { "offer_id": 3, "hour_rate": "7.00", "max_hour_rate": "8.00" }
    }
  ],
  "next_cursor": null
}
```

### Polja stavke

| Polje | Tip | Obavezno | Napomena |
|---|---|---|---|
| `id` | string | da | Stabilan i jedinstven unutar izvora. Hub ga koristi za idempotenciju (`unique(source_id, external_id)`). Preporuka: `"<vrsta>:<id>"`. |
| `kind` | enum | da | `job`, `deal`, `article`, `event`, `generic`, `comparison`, `catalog`. Određuje zadani predložak slike i caption builder. |
| `title` | string | da | Do 300 znakova. |
| `subtitle` | string | ne | Tvrtka / trgovački lanac / autor. |
| `body_text` | string | ne | **Čisti tekst bez HTML-a.** `\n` za novi red, `• ` za natuknice. Stranica radi HTML→tekst, ne hub. |
| `facts` | `[{label, value}]` | ne | Kratke činjenice koje idu na sliku i u caption (LOKACIJA, SATNICA, PLAĆA, ROK…). Do 20. |
| `badges` | `[string]` | ne | Kratke oznake (Sezonski posao, Smještaj, −43 %). Do 20. |
| `price` | objekt | ne | `current_cents`, `old_cents`, `discount_pct`, `currency` (ISO 4217), `unit_label`. Sve osim `currency` može biti `null`. |
| `cta` | `{label, url}` | ne | Poziv na akciju. Ako nema, hub koristi `url`. |
| `url` | string | da | Javni URL stavke. Ide u FB post kao link. |
| `images` | `[{url, role, alt}]` | ne | `role`: `primary` (glavna), `logo`, `gallery`. Apsolutni URL-ovi, u produkciji **https**, javno dohvatljivi (hub ih skida pri renderu). Do 10. |
| `priority` | int | ne | Veće = važnije. Hub sortira kandidate po ovome (tier, postotak popusta…). |
| `tags` | `[string]` | ne | Slobodne oznake; AI ih koristi za hashtagove. Do 50. |
| `published_at` | date-time | ne | Kad je stavka postala javna. |
| `expires_at` | date-time | ne | Nakon ovoga hub **neće** objaviti stavku (preskače zakazano). |
| `updated_at` | date-time | da | Osnova za `since`. |
| `raw` | objekt | ne | Izvorni domenski payload, proizvoljan. Ide predlošcima i AI-ju; hub ga ne interpretira. |

Sve vremenske oznake su UTC ISO 8601 (`2026-09-05T07:10:00Z`).

**`role: logo` je znak onoga čija je ponuda** (lanac, poslodavac, izdavač), ne logotip stranice koja
šalje feed. Hub izmjeri oblik znaka i posloži ga sam:

- **širok potpis** (SPAR, Konzum, ~5:1) ide na pločicu visoku 84 px (110 px na velikim slajdovima) i
  širi se koliko treba — sam je sebi ime, pa se ime ne ispisuje uz njega;
- **kvadratni ili visoki znak** (Lidl, Tommy) dobije više visine (116 px, 132 px na velikim
  slajdovima) i **ime uz sebe**, jer bi na visini potpisa pokrio petinu površine.

Zato pošalji znak **u punoj rezoluciji i bez praznog ruba oko njega** — obrezani rub hub čita kao
dio oblika i znak ispadne manji. Prozirni PNG je najbolji; SVG se mjeri po `viewBox`-u. Bez te slike
hub ispiše `subtitle` kao ime.

## Usporedba (`kind: comparison`)

Rang ponuda više izvora po jednoj brojci — npr. najpovoljnija kava u letku svakog lanca, po
kilogramu. Hub je crta kao listu redaka (`templates.kinds.comparison`), s udicom i pozivom na akciju
kao i jednu stavku.

| Polje | Značenje u usporedbi |
|---|---|
| `facts` | **Redci, od prvog.** `label` je čiji je redak (lanac), `value` brojka po kojoj se rangira („9,98 €/kg“). Hub prikaže do 5. |
| `subtitle` | Prazan. To je polje onoga čija je ponuda, a ovdje ih je više; hub bi inače jedan izvor stavio na cijelu objavu. |
| `price` | `null`. Cijena pakiranja pojedinog retka ide u `raw.rows`. |
| `body_text` | Redak po izvoru s proizvodom i cijenom pakiranja; ide u tekst objave kakav jest, red po red. |
| `images` | `primary` je proizvod prvog retka (udica ga pokazuje uz najnižu brojku), ostali `gallery`. |
| `raw.rows[]` | Neobavezno, isti redoslijed kao `facts`: `chain_name` (mora biti jednak `label`), `title` (proizvod i pakiranje), `price_cents`, `logo` i `image` (apsolutni URL-ovi). Redak kojemu `chain_name` ne odgovara hub prikaže bez tih dodataka. |
| `expires_at` | Kad prva od ponuda istječe: nakon toga usporedba više nije točna. |

## Katalog (`kind: catalog`)

Vijest „izašao je novi letak/katalog“ za video u kojem se na pravom letku dodirom dodaju tri proizvoda na listu
([`catalog-video.md`](catalog-video.md)). Jedna stavka po letku, ne po ponudi.

| Polje | Značenje u katalogu |
|---|---|
| `id` | `catalog:<id prve verzije letka>`: osvježen letak (nova verzija, novi interni id) je ista vijest, ne nova objava. |
| `title`, `subtitle` | „Konzum katalog 7.10.2026. – 13.10.2026.“, lanac. |
| `facts` | `VRIJEDI OD`, `VRIJEDI DO`, `STRANICA`, `PROIZVODA`. |
| `price` | `null`. Cijene proizvoda su u `raw.demo.taps`. |
| `cta` | `{label, url}`: poveznica na aplikaciju (`https://uselisto.com/app`); hub joj dodaje izvor kanala. |
| `url` | Javna stranica letka (hub provjerava da je živa prije objave). |
| `images` | `primary` je naslovnica; `gallery` stranice koje video prolazi; `logo` je znak lanca. |
| `expires_at` | Kraj valjanosti letka. Hub ne objavljuje letak koji ne vrijedi još 3 dana nakon objave. |
| `raw.demo` | Blok iz kojeg se slaže video, **obavezan**; bez njega stavka nije video. |

`raw.demo` (hub ga čita strogo: `App\Catalog\CatalogDemo`, odbija s razlogom):

| Polje | Značenje |
|---|---|
| `catalog_id`, `chain`, `chain_name`, `valid_from`, `valid_to` | Identitet i valjanost; datumi `YYYY-MM-DD`. |
| `chain_phrase` | „Konzumov katalog“ u obliku koji glas izgovara i naslov piše; `null` → hub kaže „katalog trgovine Konzum“. |
| `page_count`, `product_count`, `currency`, `locale` | Za zaslon aplikacije („1 / 64“, iznos, format cijene). |
| `pages[]` | `{number, url, width, height}` redom kojim se prolaze: naslovnica, pa stranice do one s dodirima. Apsolutni URL-ovi. |
| `taps[]` | **Točno 3**, redom dodirivanja: `page`, `card_id`, `product_id`, `brand`, `name`, `variant`, `price_cents` (cijeli broj), `currency`, `unit {amount, measure}`, `crop` (slika kartice koju aplikacija sprema uz stavku liste), `bbox` i `point` (normalizirano 0–1: okvir kartice i točka dodira). |
| `total_cents` | **Zbroj `price_cents` dodira**; hub odbija blok u kojem to nije. |
| `app_url`, `campaign` | `https://uselisto.com/app` i `katalog-<lanac>-<valid_from>`: hub dodaje `utm_source=<tiktok\|instagram\|facebook>` i `utm_medium=social`. |

Stranica smije birati proizvode kako hoće, ali dodir mora biti onaj koji njezina aplikacija doista prihvaća kao
„dodaj“ (Listo to provjerava istom logikom kao aplikacija, `server/api/catalogTap.ts`). Filteri izvora
(`query.kind=catalog`, `query.min_products`, `query.max_validity_days`) idu u postavke izvora kao i svi drugi.

## Kako stranica prevodi svoj domen

Stranica zna što je njezin sadržaj, hub ne mora. Nekoliko primjera preslikavanja:

| Domen | Polje u Social Feedu |
|---|---|
| Paket oglasa (Start/Plus/Premium) | `priority` 2/3/4; `raw.offer_id` |
| Satnica / plaća | `facts[{"SATNICA","7,00 €/H"}]` ili `price{…, unit_label: "€/H"}` |
| Sezonski posao, obrok, smještaj | `badges` |
| Načini prijave | `body_text` na kraju ili `cta` |
| Cijena na akciji | `price{current_cents, old_cents, discount_pct, currency}` + `badges ["−43 %"]` |
| Trgovački lanac | `subtitle` + `images[{role: logo}]` |
| Vrijedi do (katalog) | `expires_at` |
| Novi letak lanca | `kind: catalog` + `raw.demo` (vidi gore) |

## Pravila koja hub provjerava pri "Test connection"

1. Odgovor valjan po JSON Schemi (`version`, `brand`, `items`, tipovi, duljine).
2. `id` jedinstven unutar odgovora.
3. `url` i `images[].url` apsolutni; upozorenje ako nisu `https`. Relativna adresa (`/files/…`) je
   najčešća greška: preglednik je razriješi, ali hub i Meta dohvaćaju sliku sa svojih strojeva.
4. `updated_at` prisutan i parsabilan.
5. Prva slika dohvatljiva (HEAD/GET vraća 200 i `image/*`).

## Verzioniranje

Ugovor je verzioniran poljem `version`. Nekompatibilne promjene idu kao `"2"` uz paralelnu podršku
v1 u hubu. Dodavanje novih **neobaveznih** polja ne mijenja verziju.

## Rezervni ulazi (kad stranica ne može dobiti endpoint)

- **RSS / Atom / JSON Feed** — hub ih čita kao `kind=article` (`title`, `body_text`, `url`, `images`, `published_at`).
- **Webhook push** — stranica šalje `POST https://hub/api/ingest/{source}` s tijelom
  `{ "version": "1", "brand": "...", "items": [...] }` i HMAC-SHA256 potpisom **sirovog tijela** u
  zaglavlju `X-Signature` (tajna je `sources.secret` u hubu). Prihvaća se oblik `sha256=<hex>` i goli
  heksadecimalni sažetak. Ime izvora u putanji je `sources.name`. Odgovori: `200 {created, updated}`,
  `401` kriv potpis, `404` nepoznat ili isključen izvor, `422` payload ne odgovara ovoj shemi.

```bash
BODY=$(cat items.json)
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $2}')
curl -X POST "https://hub.example/api/ingest/moja-stranica" \
  -H "Content-Type: application/json" -H "X-Signature: sha256=$SIG" --data "$BODY"
```

## Referentna implementacija

`docs/examples/laravel-social-feed/` — kontroler, resource, ruta, komanda za izdavanje tokena i
feature test za Laravel stranicu. Kopira se u novi projekt i prilagodi preslikavanje.
