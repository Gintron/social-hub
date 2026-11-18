# Pinterest: tjedni pinovi kroz RSS feed

Hub **ne razgovara s Pinterestom**. Svaki tjedan nacrta pinove jela i drži RSS feed po ploči; Pinterest ga sam čita (Business račun →
Create → Bulk create / Auto-publish) i u roku od 24 sata napravi pinove. Stanje 18. 11. 2026.: račun `@uselisto` (Austrija), Business, domena
`uselisto.com` potvrđena (meta `p:domain_verify` u zaglavlju Listinih stranica).

## Zašto feed, a ne API

Nova Pinterestova aplikacija dobije **Trial** pristup: pinovi koje napravi vidi samo vlasnik računa, a 1.000 poziva dnevno je gornja granica.
Javni pinovi traže **Standard** pristup, koji se odobrava pregledom uz demo-video, bez objavljenog roka (zajednica javlja tjedne čekanja). Feed ne traži
ništa od toga: treba samo Business račun i potvrđenu domenu. Kad Standard pristup jednom stigne, kanal se može dodati kao `Platform` s
publisherom; ovaj feed tada ostaje kao rezerva.

Pinterestova pravila za feed (help.pinterest.com, „Auto-publish“): RSS 2.x ili 1.x (ne Atom); slika iz `<enclosure>`, `<media:content>` ili `<image>`;
naslov i opis iz `<title>` i `<description>`; svaka poveznica mora biti na potvrđenoj domeni; pinovi nastaju u roku od 24 h, najstariji prvi, do 200 dnevno.
Adresa samog feeda ne mora biti na potvrđenoj domeni (hub je na `hub.uselisto.com`).

## Tok

```
hub:pinterest-pins (četvrtkom 08:30)  →  PinBuilder: za svaki pin iz config/pinterest.php najbolje jelo na akciji
                                          →  ImageRenderer (kinds/recipe-pin, 1000×1500 JPEG)  →  media_assets (params.pinterest)
GET /pinterest/{brand}/{ploča}.xml    →  PinFeed: pinovi te ploče iz zadnjih `feed_days` dana, najnoviji prvi
Pinterest (svakih par sati)           →  novi <item> (guid listo-pin-<id>) postaje pin na ploči
```

* **Jedan pin po specifikaciji i tjednu** (ISO tjedan u vremenu brenda): ponovno pokretanje ne radi ništa, a propušteni četvrtak nadoknađuje petak.
* **Jelo se ne ponavlja**: isti pin nikad ne uzima jelo koje je već imao, a dva pina istog tjedna ne dijele jelo. Specifikacija bez jela (dućan bez jela
  ovaj tjedan, sva jela iskorištena) ne radi pin i piše u log.
* **Što pin kaže**: jelo na slici (AI ilustracija, s oznakom), naslov i izvor („BILLA-Prospekt“); naslov i opis počinju imenom jela („<jelo> – <naslov pina>“), jer Pinterestova pretraga traži po riječima, a ime jela ne ističe s ponudom. **Nikakva cijena, datum ni broj**: ponude jela ističu u srijedu,
  a pin živi mjesecima. Zato **poveznica vodi na stranicu koja ostaje** (`/at/rezepte/<lanac>`, `/at/rezepte`), s `utm_source=pinterest&utm_medium=social&utm_campaign=pin-<ploča>`
  (Listin web to prosljeđuje u Play `referrer` i Apple `ct`).
* **AI oznaka**: Pinterestov prekidač „KI-modifiziert“ postoji samo pri ručnoj izradi pina; feed ga ne može postaviti. Zato je oznaka na samoj slici
  (`rc-ai`) i rečenica „Bild: KI-Illustration.“ u opisu.

## Postavljanje (jednom)

Svaka ploča je jedan feed. U Pinterestu: **Business hub → Create → Bulk create content → Auto-publish** (stolno računalo), Add feed, zalijepi adresu, izaberi ploču:

| Ploča na Pinterestu | Feed |
|---|---|
| Rezepte mit BILLA-, SPAR- und Hofer-Angeboten | `https://hub.uselisto.com/pinterest/uselisto-at/angebote.xml` |
| Günstige Rezepte aus dem Prospekt | `https://hub.uselisto.com/pinterest/uselisto-at/guenstig.xml` |
| Wochenplan: günstig kochen | `https://hub.uselisto.com/pinterest/uselisto-at/wochenplan.xml` |
| Schnelle Rezepte unter 30 Minuten | `https://hub.uselisto.com/pinterest/uselisto-at/schnell.xml` |

Prije dodavanja pokreni `php artisan hub:pinterest-pins` na produkciji i otvori feed u pregledniku: mora biti XML s `<item>` (prazan feed Pinterest odbija ili ostavi bez pinova).

## Kad nešto pođe po zlu

* **Jelo čija se slika ne može nacrtati** ne zaustavlja tjedan: pin uzima sljedeće najbolje jelo (do 3 pokušaja), ostali pinovi se rade dalje. Pin koji ne uspije ni tako ide u izlaz naredbe i u log (`hub.pinterest.pin_failed`), a naredba završi s greškom.
* **Tjedan bez ijednog pina** (nema jela na akciji, svaka slika pada) logira se kao `hub.pinterest.no_pins_this_week` i naredba završi s greškom. Hub nema kanal za obavijesti, pa to vidi samo log/izlaz zakazane naredbe: povremeno pogledaj feed ili `storage/logs`.
* **Brend bez `site_url`** (apsolutni URL) ne radi pinove: poveznica bi bila relativna i Pinterest je odbija.
* Riječi koje hub dodaje (opis, AI napomena, opis i jezik feeda) su u `App\Support\Wording` po jeziku brenda; naslov, „eyebrow“ i „headline“ pina su u `config/pinterest.php` uz brend.

## Održavanje

* **Novi pin ili ploča**: `config/pinterest.php` (`boards` su feedovi, `pins` su pinovi). Nova ploča traži novi feed u Pinterestu.
* **Drugo tržište**: dodaj blok pod slug brenda; brend mora imati `site_url` na potvrđenoj domeni.
* **Ručno**: `php artisan hub:pinterest-pins [slug]`. Slika se crta Chromiumom (`/usr/local/bin/hub-chrome` u Sailu, Puppeteerov cache na produkciji); testovi ga ne trebaju (lažni `PinImageRenderer`).
* **Dnevnik**: pinovi su `media_assets` s `template_key = kinds/recipe-pin` i `params.pinterest` (`pin`, `board`, `week`, `item_id`, `title`, `description`, `link`).
* **Ako Pinterest ne stvara pinove**: provjeri feed (XML, `enclosure` s javnim https URL-om slike, poveznice na `uselisto.com`), pa Pinterestov izvještaj o feedu (Auto-publish → status).
