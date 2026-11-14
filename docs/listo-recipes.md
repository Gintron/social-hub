# Recepti iz akcija: objave jela

Listo je od 13. 11. 2026. „što kuhati ovaj tjedan iz akcija tvog dućana“, pa su **recepti glavni sadržaj**, a letci i akcije sporedni.
Hub objavljuje jelo (AI ilustraciju, naslov, koliko je sastojaka na akciji, zbroj i uštedu) i vodi na stranicu jela. Plan, odluke i
učestalost: `~/Library/Application Support/Claude/side-session-notes/…/plan-recepti-u-hubu.md` (sažetak niže).

```
Listo feed (kind=recipe)  →  hub:sync-sources (satno)  →  ContentItem(kind=recipe)
                                                            ↓
                                  AutoLineup / auto_publish_rules / digest serija
                                                            ↓
        PostDraft (čeka odobrenje) + kanal: slika 4:5 (FB), video 9:16 (IG Reel, TikTok, YouTube), carousel (tjedni pregled)
                                                            ↓
                          RenderVideoJob / RenderSlidesJob  →  odobrenje  →  objava u terminu
```

## Što je objava

Jedna stavka = jedno jelo. Slajdovi su po vrsti (`config/template_sets.php`): **udica → kartica jela → poziv**, isto za 4:5 i 9:16.

| Slajd | Što kaže |
|---|---|
| Udica (`recipe-hook`) | Jelo i jedna rečenica (≤ 2,5 s u videu): pitanje „Što kuhati ovaj tjedan?“, ili „4 sastojka na akciji“ (+ „ušteda 2,20 €“ kad je ≥ 1 €), ili „Gotovo za 25 minuta“. **Nikad zbroj prvi**: zbroj je jedno pakiranje svakog sastojka na akciji i jedan skup sastojak ga napuhne (viđeno: 35,68 €). |
| Kartica (`recipe`) | Jelo, dućan, trajanje · porcije · rok, i tri pločice: *Na akciji 4 / 7*, *Zbroj 8,96 €*, *Ušteda 2,20 €*. Sastojci kao čipovi samo uz `voice.recipe_highlights`. |
| Poziv (`recipe-cta`) | „Recept i popis za kupnju čekaju u aplikaciji Listo.“, gumb „Preuzmi Listo“ i **gdje je poveznica** (komentar, biografija ili opis, kako kanal kaže). |
| Naslovnica pregleda | Naslov serije, „5 jela iz akcija“, četiri jela s dućanom (`partials/recipe-cover`). |

Svaki slajd koji pokazuje jelo nosi **„AI ilustracija“ / „KI-Illustration“**, a tekst objave rečenicu „Slika jela je AI ilustracija.“
Platformska oznaka AI sadržaja šalje se za recepte **u svakom formatu** (`PostVariant::showsAiDish()`): TikTok `is_ai_generated`/`is_aigc`,
YouTube `containsSyntheticMedia`, Instagram `is_ai_generated` na spremniku slike, Reela i roditelja carousela (ne na djeci; Instagram Platform
changelog, 22. 6. 2026.). Facebook nema parametra, pa je ondje samo oznaka na slici i rečenica u tekstu.

**Prva sekunda je postavka brenda** (`voice.recipe_hook`): `question`, `figure`, `time` ili `alternate` (zadano: pitanje i broj naizmjence, po
jelu, ne po danu; `time` samo za jela ≤ 30 min). Kanal pamti koja je udica išla (`settings.hook`) da se doseg može usporediti nakon 24 i 72 h.
Pobjednika ne proglašavati prije ~10 objava po udici.

Riječi (glas, titlovi, naslovi, tekst, komentar) piše samo `App\Recipe\RecipeCopy` na jeziku brenda (`Wording`: `hr`, `de`); brojeve glasu
izgovara `SpokenCroatian`/`SpokenGerman`, nikad model. Naziv dućana nema padeža („u trgovini Ribola“, „bei Eurospar“).

## Poveznica i prvi komentar

Poveznica je **stranica jela** (odgovor na objavu, s gumbima za trgovine), po kanalu (`App\Recipe\RecipeVariant`, ista pravila kao letak):

- **Facebook stranica:** prvi komentar „👉 Recept i popis za kupnju: {stranica}?utm_source=facebook&utm_medium=social&utm_campaign=recept-<tjedan>“ (klikabilno, praćeno).
- **Instagram i TikTok:** u komentaru se ništa ne može dodirnuti, pa je upisiva adresa kanala („uselisto.com/instagram“, `/tiktok`;
  `catalog_video.link_typed*`). Adresa kaže kanal, ne objavu. TikTok komentar ostavlja `LeaveTikTokCommentJob` kad aplikacija ima dozvolu za komentare.
- **YouTube Short:** poveznica je u opisu (praćena), a video kaže „Poveznica je u opisu.“ (poseban video: `PrepareVariantMedia` grupira `recipe:{voice|plain}:{mjesto}`).
- **Facebook grupa:** ručni kanal, poveznica u tekstu.

## Uključivanje (radi Marijan u panelu; produkciju ne diram)

1. Listo mora imati uključene stranice recepata za tržište (`RECIPES_WEB_MARKETS`), inače feed ne šalje ništa (hub ne dobiva poveznicu na 404).
2. `./vendor/bin/sail artisan hub:recipe-source uselisto` (i `uselisto-at`): izvor `listo-recepti` (`query.kind=recipe`, ista adresa, ključ i država) i pravilo po kanalu,
   **sve isključeno, „Traži odobrenje“ uključeno**, 1 dnevno. U panelu: Izvori → *Testiraj vezu* (mora vratiti stavke vrste Recept) → uključi izvor i pravila.
3. Brendovi → Raspored objava: „Redoslijed vrsta“: **Recept prvi**, pa katalog, usporedba, akcija. Predloženi termini: pon–pet 16:30, sub–ned 10:00; dnevni limit 2.
4. Tjedni pregled (carousel): Brendovi → Pregledi → nova serija, vrsta **Recept**, format Carousel (Instagram, Facebook), npr. nedjeljom 17:00, 5 jela;
   za „Što kuhati iz Konzuma“ oznaka serije `konzum` (opće polje `tags`).
5. Glas (Luka, `eleven_v4`) za HR; za AT video izlazi s titlovima dok se ne izabere glas. Prvih ~5 objava pogledati pa isključiti „Traži odobrenje“.

Probni nacrt bez čekanja automatike: `./vendor/bin/sail artisan hub:recipe-draft --brand=uselisto --format=video --sync`.

## Što hub čuva

- `ContentItem::publicationChecksum()` uključuje `raw.recipe`: jelo kojem se zbroj, ušteda ili rok promijenio nakon nacrta ne izlazi kakvo je nacrtano (`stale_content`).
- Jelo mora vrijediti još 3 dana nakon objave (`hub.min_days_valid.recipe`); `AutoLineup` preskače jelo čiji blok ne valja (`RecipeInfo::problemsOf`).
- Alkohol: feed označava `restricted`, a hub i dalje provjerava riječi u tekstu (`config/restricted_goods.php`).
- `ScriptGuard` i `CaptionValidator` drže svaki iznos glasa i teksta uz podatke stavke.

## Što nije provjereno

- **Njemački tekstovi nisu prošli izvornog govornika.** AT objave ostaju „traži odobrenje“.
- Zvuk i naglasak (npr. „Ribola“, „Eurospar“): ne može se slušati; odlučuje uho (`hub:voiceover-test`).
- Izgled na pravom telefonu i žive objave na bilo kojem kanalu. Uzorci su renderirani na Macu (fontovi se mogu razlikovati od Linuxa).
- Instagram `is_ai_generated` na slici i carouselu: po dokumentaciji vrijedi, ali nije isprobano na živom računu (kao ni za Reel).
