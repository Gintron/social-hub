# Listo Österreich: pokretanje objava (Facebook, Instagram, YouTube)

Pripremljeno 5. 11. 2026. Brend tržišta je `uselisto-at` (vidi `hub:market-brand`, CLAUDE.md „Tržište je brend“).
Ništa se ne uključuje samo: izvori kreću isključeni, a pravila isključena i s odobrenjem.

Nisam imao uvid u produkcijsku bazu, pa je prvi korak provjera što već postoji (točka 1).

## 0. Što je provjereno u kodu i na webu

- Listo feed zna državu: `?country=at` vraća brend `uselisto-at`, njemačke tekstove („Im Prospekt ansehen“,
  „Listo herunterladen“), oznake `prospekt` / `preisvergleich` i poveznicu aplikacije `uselisto.com/app`.
  Tržište AT je pokrenuto (Listo, 30. 10. 2026.), `uselisto.com/at` odgovara (hreflang `de-AT`).
- Hub zna njemački: `Wording` (`brands.language = de`), `SpokenGerman` za glas, tekst videa „Neuer Prospekt von Billa“.
- **Praznina koju sam popravio: alkohol.** Popis zabranjenih riječi bio je samo hrvatski (+ „wine“, „beer“), pa bi
  Billa, Spar ili Hofer s vinom i pivom prošli i u videu i u tekstu. Njemačke riječi (Wein, Bier, Sekt, Schnaps,
  austrijske marke piva…) dodane su u `config/restricted_goods.php` uz izuzetke („alkoholfreies Bier“, „Weintrauben“,
  „Bierschinken“) i testove. **Isti popis mora biti u Listu** (`server/api/catalogDemo.ts`, `ALCOHOL_WORDS`): ondje
  se bira naslovnica i tri proizvoda iz letka. Izmjena je spremljena u zasebnom worktreeju
  `~/listo-at-alcohol` (grana `claude/at-alcohol-words`, testovi zeleni, **nekomitano i nepushano**; push na master
  deploya Listo produkciju, pa to ostaje tvoja odluka).
- **Redoslijed:** prvo Listo (alkohol u feedu), zatim hub, tek onda uključivanje izvora u panelu.

## 1. Što provjeriti prije

1. Postoji li brend? Panel → Brendovi, ili na Ploiju (samo čitanje):

   ```bash
   php artisan tinker --execute 'App\Models\Brand::where("slug","uselisto-at")->first()?->socialAccounts->each(fn($a) => print($a->platform->value." | ".$a->name." | ".$a->status->value."\n"));'
   ```

2. **Jesu li ostala tri Facebook odobrenja živa.** Povezivanje stranica za Austriju zamjenjuje popis stranica koje
   odobrenje pokriva (CLAUDE.md „Facebook odobrenje je jedno po korisniku × aplikaciji“). Ako u dijalogu nisu bile označene
   i Listo HR, Studentski poslovi i Radim, njihovi tokeni umiru. Nakon povezivanja: `php artisan hub:verify-accounts`
   i u panelu provjeri da je svaki Meta račun „Aktivan“, ne „Treba ponovno povezati“.
3. **YouTube račun mora biti kanal brenda, ne osobni** (do 5. 11. jedini povezani je osobni `@marijanklaric4269`).
   Prvi javni upload je ujedno probni: `publish_logs` → `youtube.upload` → `status.privacyStatus` (vidi `docs/youtube.md`).

## 2. Brend (ako ga još nema)

```bash
php artisan hub:market-brand uselisto uselisto-at --country=at --language=de --dry-run \
  --name="Listo Österreich" --site-url=https://uselisto.com/at --cta="Listo herunterladen" \
  --pitch="Prospekt → deine Einkaufsliste." --activation="Füge das erste Produkt aus dem Prospekt hinzu." \
  --hashtags="prospekt,angebote,aktion,wochenangebote,sparen,einkaufsliste,österreich"
```

Bez `--dry-run` radi stvarno. Naredba kopira izvore Lista s `query.country=at` (isključeni) i njihova pravila
(isključena, traže odobrenje). Ako su računi već povezani s ručno napravljenim brendom, ne radi se drugi: samo provjeri da
brend ima iste postavke kao dolje.

Što u panelu (Brendovi → Listo Österreich) treba provjeriti jer se kopira iz hrvatskog:

| Postavka | Kopira se | Predlažem za AT |
|---|---|---|
| Jezik / vremenska zona | `de` / `Europe/Vienna` | ostaje |
| Termini objave | isti kao HR (npr. 18:30) | ostaviti 18:30 uz jedan jutarnji (~07:30) kao hipotezu; mjeriti |
| Dnevni limit | 3 | **2** dok nema podataka (račun je bez pratitelja, više objava ne pomaže dosegu) |
| Pregledi (serije) | ne kopiraju se | bez njih; vidi § 4 |
| Glas (voice-over) | isključen, bez glasa | glas bira uho: `hub:voiceover-test "Hey, der neue Prospekt von Billa ist da." --brand=uselisto-at --voice=<id>`. Bez glasa video izlazi s titlovima, to je dopušteno. |
| Izgovor imena | prazno | Billa, Spar, Hofer, Lidl, MPREIS, Müller, Bipa: provjeriti uhom |
| Lineup (`auto_lineup`) | kopira se | ostavi; vrste redom katalog, usporedba, akcija |

## 3. Izvori i pravila (panel → Izvori)

Izvori nastali naredbom: `listo-at`, `listo-katalozi-at` (+ `listo-usporedbe-at` ako postoji u HR). Za svaki: **Test connection**
(mora javiti brend `uselisto-at` bez upozorenja), zatim uključiti. Pravila po izvoru × kanalu:

| Izvor | Kanal | Format | Najviše dnevno | Odobrenje |
|---|---|---|---|---|
| `listo-katalozi-at` | Facebook stranica | Video | 1 | traži (prvih ~7 dana) |
| `listo-katalozi-at` | Instagram | Video (Reel + feed) | 1 | traži |
| `listo-katalozi-at` | YouTube | Video (Short) | 1 | traži |
| `listo-usporedbe-at` | isto troje | Video | 1 uz tjedni ritam | traži |
| `listo-at` (pojedinačne akcije) | ostaviti **isključeno** | | | |

YouTube pravila hrvatski izvori nemaju, pa ih treba dodati ručno (naredba kopira samo ono što HR ima).
Prvih dana odobrenje ostaje uključeno jer njemački tekst, glas i izgled videa nikad nisu viđeni uživo; kad pet objava prođe
bez ispravaka, isključiti (kao što je 8. 10. napravljeno za HR).

Prva objava ručno, prije automatike: `php artisan hub:catalog-draft --brand=uselisto-at --platforms=fb_page,ig_business,youtube`
(bez `--approve`), pogledati video i tekst u panelu pa odobriti.

## 4. Preporuka: koji oblik objave za Austriju

Polazište je ono što je izmjereno za Hrvatsku: FB i IG su bez pratitelja (doseg 0–10), TikTok ~400 pregleda po videu,
a jedini oblik s jasnim ciljem (instalacija) je video „novi katalog“. Austrija počinje isto: **nema publike, pa objava
mora nositi sama sebe**: preko preporuke (Reels, Shorts) i pretrage (YouTube). Ono što slijedi su hipoteze za test, ne izmjereni
rezultati za AT.

1. **Glavni oblik: „Neuer Prospekt von <lanac>“, 9:16, ~16 s** (isti video na Reelsu, FB Reelu i Shortsu).
   - Konkretan i vremenski osjetljiv: ljudi u Austriji traže upravo „billa angebote“, „hofer flugblatt“, „spar prospekt“
     (isti upiti su u Apple Ads planu, `listo/docs/marketing/apple-ads-at.md`), pa naslov s imenom lanca i datumima
     („Neuer BILLA Prospekt, gültig 5.–11.11.“) hvata pretragu, posebno na YouTubeu gdje video živi mjesecima.
   - Radi i bez glasa (titlovi), što je sigurno za početak.
   - Devet lanaca s letcima tjedno daje dovoljno materijala; limit 1 po kanalu dnevno znači da lineup bira prema `lineup_priority`.
2. **Dopuna, 1× tjedno: usporedba cijene po kg/l** (Reel). Za tržište s jakim lancima (BILLA, SPAR, Hofer, Lidl, Penny) usporedba
   je razlog za dijeljenje i spremanje, što katalog sam nije. Prije uključivanja provjeri da AT feed ima dovoljno proizvoda
   u više lanaca (`?kind=comparison&country=at`); ako nema, preskoči.
3. **Rijetko: carousel „Die Top 3 Angebote bei <lanac>“ (FB/IG), najviše 1× tjedno** i samo kad je serija s oznakom lanca
   (`DigestSeries`). U HR su sedam dnevnih pregleda dali slab doseg, pa ih ne preslikavati.
4. **Ne za AT:** dnevne pojedinačne slike akcija, više od 2 objave dnevno, sve što sadrži alkohol (sada blokirano).

Ton i riječi: „du“ (kao u aplikaciji i App Store tekstu), „Prospekt“ / „Angebote“ / „Aktion“ (austrijski „Aktion“ za sniženje),
ne hrvatske kalkove. Tekst videa i opisa piše hub (`Wording`, `CatalogCopy`), ništa se ne piše na hrvatskom.

Termini (hipoteza): večernji 17:30–19:30 (planiranje vikend-kupnje) i jedan jutarnji ~07:30. Letci kreću većinom u ponedjeljak ili
četvrtak (Penny četvrtak–srijeda, prema `listo/docs/austrija-rucni-upload.md`), pa hub objavljuje u prvom terminu nakon sinkronizacije.
Ne mijenjati termine tijekom prva dva tjedna, da usporedba bude čitljiva.

## 5. Poveznice

- Facebook: poveznica u prvom komentaru, praćena (`utm_source=facebook`).
- Instagram: komentar kaže samo `uselisto.com/instagram` (ne može se dodirnuti, prepisuje se).
- YouTube: poveznica u opisu, praćena.

**Poznata rupa u Listu (nije hub):** `uselisto.com/app` i `/instagram` na mobitelu vode u trgovinu, ali na računalu
na hrvatsku naslovnicu (`server/api/pages.ts`, `pathFor("hr", "landing")`; provjereno 5. 11. 2026.: `/app` → `uselisto.com/`). Austrijanac koji
prepiše adresu na računalu vidi hrvatski tekst. Ispravak je u Listu (kratke adrese po tržištu, npr. `/at/app`) i traži njegov
deploy; hub za to nema postavku po brendu (`catalog_video.link_typed_path` je globalan). Također provjeriti na iPhoneu s
austrijskim računom da `/instagram` otvara AT stranicu aplikacije, a ne hrvatsku.

## 6. Mjerenje

Kao u `docs/listo-video-growth.md`: pregledi, prosječno gledanje, dovršenost i klikovi po kanalu nakon 24 i 72 sata;
prazno polje znači „nije izmjereno“, ne nula. Krajnja mjera je nova aktivirana osoba iz Austrije (Listo ima `users.market`), ne
pregled. Nakon dva tjedna: što od tri oblika donosi najviše instalacija po objavi, pa tek onda povećati limit ili uključiti
TikTok za AT (nije povezan).
