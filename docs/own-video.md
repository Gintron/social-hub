# Gotov video na kanalima brenda (`hub:video-post`)

Sve ostalo u hubu kreće od stavke koju je izvor poslao i sam renderira sliku ili video. Ovdje je **video sadržaj**: napravljen
je drugdje (avatar voditeljica, snimljen isječak) i hub ga samo objavljuje, s riječima koje je čovjek napisao za svaki kanal.

## Što se događa

`App\Actions\CreateVideoPost` (isti put za naredbu i za svaki budući zaslon):

1. Provjeri sve **prije** nego išta spremi: kanal postoji i prima video (ne grupa), ima aktivan račun, ima tekst; datoteka je
   čitljiv video od najmanje sekunde (`ffprobe`); isti video nema već živu objavu.
2. Spremi video (i naslovnu sliku kao JPEG 1080 px) na javni disk u `media/{brend}/{god}/{mj}/…` — isto mjesto kao renderirani
   videi, pa vrijedi i verificirani URL prefiks za TikTok Business (`storage/media/`).
3. Napravi običnu stavku (`kind=generic`) u izvoru **„Gotovi videi”** (push, nitko ga ne povlači, nijedno pravilo automatike ga ne
   čita, pa ga pregled nikad ne uzme). Zato i dalje rade ista čuvanja kao za svaku objavu: `expires_at` (`valid_until`), poveznica
   stavke mora odgovarati (`LinkPreflight`), „sadržaj se promijenio otkad je nacrt napravljen”.
4. Napravi nacrt s jednom varijantom po aktivnom računu kanala: format `video`, video priložen, tekst, prvi komentar i
   `ai_generated` kako je zadano. Čeka odobrenje osim uz `--approve`; objavljuje scheduler u zadano vrijeme.

Tekst i komentare hub **ne piše i ne provjerava** (`CaptionValidator` je za tekstove koje je napisao model): dao ih je čovjek, vidi
ih na ekranu objave kao i svaki drugi nacrt.

## Manifest

```json
{
  "title": "Pilot: popis iz letaka",
  "url": "https://uselisto.com/app",
  "video": "pilot.mp4",
  "cover": "naslovna.png",
  "valid_until": "2026-10-27 23:59",
  "ai_generated": true,
  "channels": {
    "tiktok": {
      "caption": "…",
      "first_comment": "👉 Preuzmi Listo: uselisto.com/tiktok",
      "link_url": "https://uselisto.com/tiktok",
      "cover_timestamp_ms": 900
    },
    "ig_business": { "caption": "…", "first_comment": "…" },
    "fb_page": { "caption": "…", "first_comment": "👉 Preuzmi Listo: https://uselisto.com/app?utm_source=facebook&…" }
  }
}
```

- Putovi su relativni prema manifestu. `valid_until` je lokalno vrijeme brenda; poslije njega objava se preskače kao istekla
  (npr. letak na zaslonu koji je istekao).
- `url` je stranica na koju objava vodi; pita se odgovara li prije objave. `link_url` kanala je njegov vlastiti (kratka adresa kanala).
- `ai_generated` (zadano `true`) označava objavu kao AI-generirani sadržaj tamo gdje je to parametar API-ja (TikTok, Instagram,
  YouTube). Facebook Reels API nema parametar: oznaka se tamo stavlja ručno ako ju Meta ne postavi sama.
- Dopuštene `settings` po kanalu su iste kao na ekranu objave (`UpdateVariant::EDITABLE_SETTINGS`).

## Pokretanje

```bash
./vendor/bin/sail artisan hub:video-post storage/app/imports/pilot/objava.json --brand=uselisto --at="2026-10-20 19:30" --approve
```

Bez `--at` vrijeme je sljedeći prozor objave brenda; bez `--approve` objava čeka odobrenje u panelu. Isti video ne može dvaput
dobiti živu objavu (odbačena ili preskočena se ne računa).

## Dnevni limit brenda

Objava koju je napravio čovjek **broji se** u `brands.daily_post_limit` (zaustavlja samo automatiku): na dan s ručnom objavom
automatika ima jedan termin manje.
