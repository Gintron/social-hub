# TikTok: što treba prije prve objave

TikTok objavljivanje radi kroz **Content Posting API** (Direct Post). Za razliku od Mete, gdje je za
vlastite stranice dovoljna aplikacija u razvojnom modu, TikTok ima dva zida koja se ne daju zaobići
kodom.

## Zid 1: audit

Dok aplikacija ne prođe TikTokov audit:

- svaka objava je **SELF_ONLY** — vidi je samo vlasnik računa,
- račun na koji se objavljuje mora biti **privatan** u trenutku objave,
- najviše **5 korisnika u 24 sata** smije objavljivati kroz aplikaciju.

Hub to ne pokušava zaobići: čita dopuštene razine privatnosti iz TikTokova odgovora
(`creator_info/query`) i pada natrag na `SELF_ONLY` kad tražena razina nije dopuštena. Greška
`unaudited_client_can_only_post_to_private_accounts` prevodi se u poruku koja to kaže.

Audit se traži u developer portalu nakon što integracija radi. TikTok traži da sučelje prije objave
pokaže korisničko ime kreatora i prekidače za komentare, duet i stitch — hub to ima u pregledu
objave, pa je taj uvjet ispunjen.

## Zid 2: verifikacija domene

Hub objavljuje kroz `PULL_FROM_URL`: TikTok sam dohvaća video s naše adrese. To radi **samo ako je
domena verificirana** u developer portalu (URL properties → verifikacija preko datoteke ili DNS
zapisa). Bez toga TikTok odbija dohvat, a greška ne kaže jasno zašto.

Verificirati treba domenu s koje se poslužuju mediji — kod nas `hub.<domena>`, odakle idu i slike za
Metu. Alternativa je `FILE_UPLOAD` (slanje bajtova u komadima), koja nije implementirana jer traži
chunked upload i ne donosi ništa dok domena ionako mora biti javna zbog Instagrama.

## Postavljanje

1. Developer portal → nova aplikacija → dodaj proizvod **Content Posting API** i **Login Kit**.
2. Scopeovi: `user.info.basic`, `video.publish` (Direct Post) i `video.upload` (nacrt u inboxu).
3. Redirect URI: `https://hub.<domena>/tiktok/callback`.
4. Verificiraj domenu `hub.<domena>`.
5. U `.env`:

```
TIKTOK_CLIENT_KEY=…
TIKTOK_CLIENT_SECRET=…
TIKTOK_REDIRECT_URI=https://hub.<domena>/tiktok/callback
TIKTOK_PRIVACY_LEVEL=SELF_ONLY
```

6. Panel → Postavke → Društveni računi → **Poveži TikTok**.

## Tokeni istječu, za razliku od Metinih

Pristupni token vrijedi **24 sata**, refresh token oko **godinu dana**, i TikTok pri svakom
osvježavanju izda **novi** refresh token. Zato:

- `hub:refresh-tiktok-tokens` ide **svaki sat** iz schedulera i osvježava sve kojima token istječe
  unutar 6 sati,
- oba tokena stoje u šifriranim stupcima (`access_token`, `refresh_token`),
- ako osvježavanje padne, račun ide u „treba ponovno povezati" i stiže mail — objava se neće tiho
  prekinuti usred rasporeda.

## Ograničenja videa

| Pravilo | Vrijednost |
|---|---|
| Format | MP4, H.264, AAC, `yuv420p` |
| Trajanje | najmanje 3 s; gornju granicu vraća sam račun (`max_video_post_duration_sec`) |
| Omjer | uspravno, 9:16 (hub renderira 1080×1920) |
| Adresa | javni **https** URL na verificiranoj domeni |
| Zvuk | umiksan u MP4 iz knjižnice brenda; API ne može dodati TikTokov zvuk |

Hub sve to provjeri prije poziva i odbije s razumljivom porukom, umjesto da potroši objavu na
TikTokovu generičku grešku.

## Video se radi od istih predložaka

`hub:render-video` renderira postojeće predloške u 9:16 i spoji ih u MP4 s prijelazima
(`App\Rendering\VideoRenderer`, ffmpeg). Isti video ide na TikTok, Instagram Reels i Facebook Reels —
nema drugog dizajna za video, samo druga visina.

```bash
php artisan hub:render-video --brand=uselisto --kind=deal --count=3 --seconds=3
```

U panelu je to akcija **Renderiraj video** na nacrtu.

Slajdovi se po defaultu lagano zumiraju (Ken Burns, ≤5 %, centrirano da tekst predloška ostane u
kadru); `--no-motion` ili prekidač u akciji vraća statične slajdove.

## Foto objave

Format TikTok varijante bira se na nacrtu: **Video**, **Slika** ili **Carousel**. Slika i carousel
idu kao TikTok foto objava (`post/publish/content/init/`, `media_type = PHOTO`):

- do 35 slika (JPEG/WEBP, ≤ 20 MB), prva je naslovnica; hub ih renderira uspravno (9:16),
- tekst se dijeli: **prvi redak je naslov** (do 90 znakova), cijeli tekst ide u opis (do 4000);
  TikTok broji UTF-16 jedinice, pa emoji troši dvije,
- `auto_add_music` (uključeno po defaultu) — TikTok sam doda glazbu ispod fotografija; pjesmu
  API ne može odabrati,
- isti zidovi kao video: vidljivost iz `creator_info`, domena verificirana, a inbox način
  (`MEDIA_UPLOAD`) radi i za fotografije.

## Zvuk

Content Posting API **nema parametar za zvuk iz TikTokove knjižnice** — što god svira ispod videa,
mora već biti u MP4 datoteci. Zato dva puta:

1. **Knjižnica brenda** (Brendovi → *Zvuk za video*): učitaš pjesme za koje brend ima prava
   (royalty-free ili licencirane; poslovni računi ne smiju koristiti komercijalnu glazbu bez licence).
   `VideoRenderer` pjesmu petlja ili reže na duljinu videa, normalizira glasnoću (−16 LUFS) i utišava
   na kraju. `auto` rotira pjesme po nacrtu, pa objave ne zvuče sve isto, a ponovni render zadrži
   istu. Isti video s istim zvukom ide i na Reels.

   ```bash
   php artisan hub:render-video --brand=uselisto --kind=deal --count=3 --audio=auto
   ```

2. **Inbox** (`settings.delivery = inbox` na TikTok varijanti): hub pošalje video kao nacrt u
   TikTok inbox kreatora (`video.upload` scope). U aplikaciji dodaš trending zvuk, zalijepiš tekst
   i objaviš, a u hubu varijantu označiš **Označi kao ručno objavljeno**. Do tada varijanta stoji u
   „čeka ručnu objavu", kao Facebook grupe.

Za Direct Post TikTokove smjernice traže da korisnik prije objave vidi izjavu o pristanku na
[Music Usage Confirmation](https://www.tiktok.com/legal/page/global/music-usage-confirmation/en);
hub je prikazuje u potvrdi **Objavi sada** kad nacrt ima TikTok varijantu.
