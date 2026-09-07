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
| `kind` | enum | da | `job`, `deal`, `article`, `event`, `generic`. Određuje zadani predložak slike i caption builder. |
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
