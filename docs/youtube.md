# YouTube Shorts

Hub objavljuje videe kao YouTube Shorts na kanal brenda. Videi su isti koji idu na Reels i TikTok
(9:16, 1080×1920, render iz istih slajdova); nema zasebnog dizajna. Kod je u
`app/Publishing/YouTube/` (OAuth, token, klijent, preflight, publisher), metrike u
`app/Metrics/YouTubeMetrics.php`, konfiguracija u `config/youtube.php`.

Stanje (2026-10-13): kod je gotov i pokriven testovima s lažnim HTTP odgovorima. Prvi upload
na stvarnom kanalu čeka Google klijent (postavljanje ispod) i odluku o auditu.

## Kako izgleda objava

- **Format**: samo video. Short je uspravan video do 3 minute; hub ne šalje ništa drugo.
  Provjera (`YouTubePreflight`) prije prvog poziva: jedan MP4, uspravan (visina > širina),
  najviše 180 s, datoteka mora biti na disku.
- **Naslov**: prvi red teksta, do 100 znakova. YouTube broji UTF-16 jedinice, pa je emoji dva.
  Predugačak naslov iz feeda (stavka, citat, naslov serije pregleda) hub pri pisanju nacrta skrati s „…“,
  a **cijeli red kaže prvim redom opisa**: citat se ne smije izgubiti zato što naslov ima 100 znakova.
  Naslov koji čovjek upiše ručno se ne skraćuje: objava se ne pusti i pokaže se razlog.
  Ni naslov ni opis ne smiju sadržavati znakove `<` i `>` (Data API: „all valid UTF-8 characters except < and >“);
  preflight to javlja prije prvog poziva (`title_invalid`, `description_invalid`), a ne tek kad Google odbije upload.
- **Opis**: ostatak teksta, do 5000 bajtova. Poveznica na stranicu stavke je u opisu i klikabilna
  (za razliku od TikToka), a `#Shorts` označava video.
- **Vidljivost**: `settings.privacy` = `public` | `unlisted` | `private`. Zadano je `YOUTUBE_PRIVACY`.
  Bira se na nacrtu (kanal YouTube), u pravilu automatske objave i preko MCP-a (`hub.update_variant`).
- **Kategorija**: `YOUTUBE_CATEGORY_ID`, zadano `22` (People & Blogs). Hub uvijek šalje
  `selfDeclaredMadeForKids=false`. Sadržaj namijenjen djeci hub zasad ne podržava.
- **Sintetički sadržaj**: video sa sintetičkim glasom (voice-over) ide s `status.containsSyntheticMedia=true`, kao i na
  TikToku i Instagramu (`PostVariant::aiGenerated()`; kanal to može isključiti ili tražiti postavkom `ai_generated`).
  Video bez sintetičkog glasa ne šalje ništa. Slanje na živom kanalu nije isprobano.
- **Upload**: resumable upload u dva koraka. Prvo `POST …/upload/youtube/v3/videos?uploadType=resumable`
  s metapodacima (dobije se `Location`), zatim `PUT` cijele datoteke na tu adresu. Uspjeh je `201`
  s resursom videa; `id` se sprema kao `external_post_id`, pa se objava nikad ne ponavlja.
- **Poveznica**: `https://www.youtube.com/shorts/{id}`.
- **Metrike**: `videos.list?part=statistics` (pregledi, lajkovi, komentari) pri `hub:collect-metrics`,
  na istom rasporedu kao Meta i TikTok.

## Postavljanje u Google Cloud

1. Otvori Google Cloud Console i napravi projekt (npr. „Social Hub“).
2. **APIs & Services → Library → YouTube Data API v3 → Enable.**
3. **OAuth consent screen**: User type **External**. Upiši naziv aplikacije, kontakt e-mail,
   domenu `hub.uselisto.com`, politiku privatnosti `https://hub.uselisto.com/privatnost` i uvjete
   `https://hub.uselisto.com/uvjeti`. U **Scopes** dodaj `youtube.upload` i `youtube.readonly`.
4. Na istom ekranu klikni **Publish app** (status **In production**). U statusu *Testing* refresh
   token istječe nakon sedam dana i račun bi trebalo stalno ponovno povezivati. U produkciji bez
   Googleove verifikacije dijalog pokazuje upozorenje „neprovjerena aplikacija“ i dopušta najviše
   100 korisnika, što je dovoljno za račune koje posjeduje Croobo.
5. **Credentials → Create credentials → OAuth client ID → Web application.**
   **Authorized redirect URI** mora biti točno ovo, bez kose crte na kraju:
   `https://hub.uselisto.com/youtube/callback` (lokalno: `http://localhost:8100/youtube/callback`).
6. U `.env` (na Ploiju u environment varijablama, pa **Deploy now**):

   ```
   YOUTUBE_CLIENT_ID=
   YOUTUBE_CLIENT_SECRET=
   YOUTUBE_REDIRECT_URI="${APP_URL}/youtube/callback"
   YOUTUBE_PRIVACY=public
   ```

7. Provjeri konfiguraciju naredbom `./vendor/bin/sail artisan hub:doctor` (red „YouTube config“).
8. U panelu: **Društveni računi → Poveži YouTube**, odaberi brend. U Google dijalogu prijavi se
   računom koji upravlja kanalom brenda i odaberi taj kanal. Hub spremi kanal, njegov identifikator
   i refresh token. Isti kanal ne može biti povezan s dva brenda.

## Audit i kvota

- **Audit.** Google uploade iz projekta koji nije prošao YouTube API audit (projekti stvoreni nakon
  28. 7. 2020.) ostavlja privatnima, bez ikakve greške. Hub to ne može spriječiti, ali zapisuje:
  odgovor na `PUT` sadrži `status.privacyStatus` (vidi `publish_logs`), a ako se razlikuje od
  tražene vidljivosti, u logu stoji `hub.youtube.privacy_adjusted`. Objava se i dalje računa kao
  objavljena, a `permalink` vodi na privatni Short koji vidi samo vlasnik kanala.
  Zahtjev za audit šalje se preko Googleova obrasca *YouTube API Services – Audit and Quota
  Extension*; poveznica i uvjeti su na <https://developers.google.com/youtube/v3/guides/quota_and_compliance_audits>.
  Audit je ljudski posao i traje; do odobrenja su svi uploadi privatni.
- **Kvota.** Prema službenoj stranici kvota (provjereno 2026-10-13): `videos.insert` ima od
  1. 6. 2026. vlastitu kvotu, zadano 100 poziva dnevno po projektu. Ostali pozivi (kanali,
  `videos.list`) dijele 10 000 jedinica dnevno. Stvarne brojke provjeri u Cloud Consoleu
  (APIs & Services → YouTube Data API v3 → Quotas), jer se Googleove vrijednosti mijenjaju.
  Hub ne računa jedinice: pri `403 quotaExceeded` (ili `uploadLimitExceeded`) objava čeka do
  ponoći po pacifičkom vremenu i pokuša ponovno.

## Tokeni

- Pristupni token traje sat. Hub ga osvježi **pri korištenju**, ako istječe za manje od pet minuta
  (`YouTubeTokens`), a ne po rasporedu. Google ne rotira refresh token, pa se onaj spremljen čuva.
- `invalid_grant` znači da je odobrenje poništeno (vlasnik je uklonio aplikaciju ili je istekao
  testni status). Račun dobije status *Treba ponovno povezati*, admin dobije obavijest, a objava
  ide u *Greška* s razlogom. Ponovno povezivanje u panelu.
- Kod ne sprema tokene u logove: zahtjevi u `publish_logs` nose samo parametre i tijelo odgovora.

## Ograničenja i rizici

- **Veličina i vrijeme.** Video ide u jednom zahtjevu. `YOUTUBE_UPLOAD_TIMEOUT` (zadano 100 s) je
  manji od 120 s, nakon kojih se gasi objavljivački posao. Spora veza tako daje prijelaznu grešku,
  a sljedeći pokušaj otvara novu sesiju. Videi od ~90 s (Reel s glasom) su daleko ispod.
- **Dupli upload.** Ako Google primi video, a odgovor se izgubi, sljedeći pokušaj bi uploadao
  drugu kopiju. Hub to ne može znati pa odgovor bez `id`-a ne ponavlja automatski: provjeri kanal
  u YouTube Studiu prije ponovne objave.
- **Prvi komentar** nije implementiran. Poveznica je u opisu, a to je i klikabilno.
- **Samo Shorts.** Obični videi (horizontalni ili dulji od 3 minute) nisu podržani: hub ih ne proizvodi.
- **Zakazivanje.** Hub sam bira termin (`PostingSchedule`); YouTube `publishAt` se ne koristi.

## Probni upload

Prvi put objavi jedan nacrt na testni kanal s vidljivošću `private` i provjeri u YouTube Studiu:
naslov (prvi red), opis (poveznica klikabilna, `#Shorts` na kraju), trajanje, uspravan okvir
i je li Short. Zatim u `publish_logs` pogledaj odgovor na `youtube.upload`: `status.privacyStatus`
pokazuje je li kanal već prošao audit (ako je tražena javna objava, a dobiješ `private`, još nije).
