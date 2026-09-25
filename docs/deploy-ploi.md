# Postavljanje huba na VPS preko Ploija

Hub je običan Laravel (PHP 8.4) site na istom VPS-u kao `api.uselisto.com`. Ploi drži Nginx, PHP-FPM,
Let's Encrypt, supervisor (queue worker) i cron (scheduler). **Bez Dockera** — Sail iz repoa je samo za
lokalni rad.

## 1. Site

Ploi → Sites → Add site: domena `hub.<tvoja-domena>`, PHP 8.4, web directory `/public`.
DNS A zapis na IP servera prije Let's Encrypta.

Ploi → Databases: MySQL baza `social_hub` + korisnik.

## 2. Sistemske ovisnosti (jednom, SSH kao root ili sudo)

```bash
# Chromium ovisnosti + fontovi (za Browsershot rendering slika)
sudo apt-get update && sudo apt-get install -y fonts-noto-core fonts-noto-color-emoji fonts-liberation \
  libnss3 libatk1.0-0 libatk-bridge2.0-0 libcups2 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 \
  libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 libasound2
node --version   # Ploi instalira Node; treba 20+
```

Chrome za Puppeteer instalira se kao `ploi` korisnik nakon prvog `npm ci` (korak 4).

## 3. Okolina (Ploi → Site → Environment)

Predložak je `.env.example`. Obavezno:

| Varijabla | Vrijednost |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://hub.<domena>` — **mora biti javno dostupan**, Meta dohvaća slike s njega |
| `APP_KEY` | `php artisan key:generate --show` |
| `DB_*` | iz Ploi Databases |
| `QUEUE_CONNECTION` | `database` |
| `FILESYSTEM_DISK` | `public` |
| `HUB_ADMIN_EMAILS` | zarezom odvojeni e-mailovi koji smiju u panel |
| `HUB_CHROME_PATH` | prazno (Puppeteerov cache) ili puna putanja do Chromea |
| `META_APP_ID`, `META_APP_SECRET`, `META_GRAPH_VERSION` | iz Meta developer dashboarda |
| `MAIL_*` | za notifikacije (odobrenja, greške, tokeni) |
| `ANTHROPIC_API_KEY` | faza 4 (agent) |

## 4. Deploy skripta (Ploi → Site → Deploy script)

```bash
cd /home/ploi/hub.<domena>
git pull origin master

composer install --no-dev --optimize-autoloader --no-interaction
npm ci --omit=dev
npx puppeteer browsers install chrome      # no-op kad je već instaliran

php artisan migrate --force
php artisan storage:link
php artisan filament:assets
# Namjerno bez `optimize`: config:cache + opcache znači da promjena varijable
# okoline ne stigne do PHP-FPM-a dok ga se ručno ne reloada. Rute i pogledi
# se predmemoriraju, konfiguracija se čita iz .env na svaki zahtjev.
php artisan route:cache
php artisan view:cache
php artisan queue:restart

# Opcache na ovom serveru ne provjerava datoteke: bez reloada PHP-FPM vrti stari kod dok CLI
# (migracije, red) vrti novi. 25. 09. 2026. migracija je upisala novi status nacrta, a panel ga
# sa starim enumom nije znao pročitati — 500 na svakoj stranici koja ga dotakne.
echo "" | sudo -S service php8.4-fpm reload
```

Ako ipak želiš `php artisan optimize`, onda **svaka** promjena u Environmentu traži i reload
PHP-FPM-a nakon deploya — inače site vrti staru konfiguraciju, a CLI novu, pa `hub:doctor` bude
zelen dok panel tvrdi da varijabla nije postavljena.

`storage/app/public/media` drži renderirane slike; **ne smije** biti u nečemu što deploy briše.

## 5. Queue worker (Ploi → Site → Queue)

| Polje | Vrijednost |
|---|---|
| Connection | `database` |
| Queue | `publish,render,default` |
| Processes | 1 |
| Timeout | 180 |
| Tries | 3 |

Jedan proces je dovoljan i namjerno: `PublishVariantJob` je `ShouldBeUnique`, a paralelni workeri samo
bi dizali IG limite. Rendering traje 1–3 s po slici.

## 6. Scheduler (Ploi → Site → Scheduler ili Cron)

```
* * * * * php /home/ploi/hub.<domena>/artisan schedule:run >> /dev/null 2>&1
```

Raspored živi u `routes/console.php`: `hub:publish-due` svake minute, `hub:sync-sources` satno,
`hub:verify-accounts` dnevno 06:00 (Europe/Zagreb).

## 7. Prvi korisnik i provjera

```bash
php artisan make:filament-user            # e-mail mora biti u HUB_ADMIN_EMAILS
php artisan hub:doctor                    # DB, storage link, javni URL medija, Chromium, fontovi, Meta config
```

`hub:doctor` mora imati **Public media URL = OK** — bez toga Instagram i Facebook ne mogu dohvatiti slike.

## 8. Meta aplikacija

Developer dashboard → tvoja app → **Settings → Basic**:

| Polje | Vrijednost |
|---|---|
| App Domains | `hub.<domena>` |
| Privacy Policy URL | javna stranica s pravilima |
| **Deauthorize Callback URL** | `https://hub.<domena>/meta/deauthorize` |
| **Data Deletion Request URL** | `https://hub.<domena>/meta/data-deletion` |

**Facebook Login for Business → Settings → Valid OAuth Redirect URIs**: `https://hub.<domena>/meta/callback`
(ista vrijednost ide u `META_REDIRECT_URI`).

U **Configurations** napravi konfiguraciju s dozvolama `pages_show_list`, `pages_manage_posts`,
`pages_read_engagement`, `instagram_basic`, `instagram_content_publish`, a za mjerenje i
`instagram_manage_insights`, `read_insights`, `pages_read_user_content` (lajkovi i komentari objava
stranice), `pages_manage_engagement` (hub ostavlja link objave stranice u prvom komentaru), i njezin
id upiši u `META_LOGIN_CONFIG_ID`. Nova dozvola u konfiguraciji vrijedi tek kad
se račun ponovno poveže. Bez konfiguracije hub šalje klasičan `scope` popis iz `config/meta.php`.

Aplikacija smije ostati u **Development modu** dok objavljuje na vlastite stranice: svatko tko ima
ulogu u aplikaciji (Admin/Developer/Tester) daje dozvole bez App Reviewa. App Review i Business
Verification trebaju tek ako se hub ponudi trećima.

Oba callbacka provjeravaju Metin `signed_request` potpis app secretom; kriv potpis vraća 400.
Deauthorize označava račune tog Facebook korisnika kao „treba ponovno povezati", a data deletion
briše tokene, isključuje račune i vraća potvrdni kod sa statusnom stranicom.

## 9. Spajanje brenda

1. Panel → Postavke → Brendovi → Novi (slug jednak `brand` polju u feedu stranice).
2. Postavke → Izvori → Novi: URL feeda + token → **Testiraj vezu** → **Sinkroniziraj**.
   Ako stranica prima vlastite filtere, upiši ih u **Napredno → Postavke** kao `query.<ime>`
   (npr. `query.country` = `hr`, `query.min_discount` = `40`). Bez toga veliki katalog probije
   granicu od 50 stranica po sinkronizaciji i sinkronizacija odustane.
3. Postavke → Društveni računi → **Poveži preko Facebooka**: odaberi brend, prođi kroz Facebook
   dijalog i hub sprema Page tokene (ne istječu) i Instagram Business račune povezane s tim
   stranicama. Alternativa bez preglednika: **Zalijepi token ručno** (System User token).
   Za Facebook grupe → **Dodaj ručno** (platforma Facebook grupa, URL grupe).
4. Sadržaj → Kandidati → **Napravi objavu**; pregled, odobrenje i objava u Sadržaj → Objave,
   raspored u Sadržaj → Kalendar.

**Instagram traži**: Business račun povezan sa stranicom (Creator ne može objavljivati preko API-ja),
JPEG omjera između 4:5 i 1.91:1, do 8 MB, najviše 10 slajdova u carouselu (svi istog omjera),
2 200 znakova i 30 hashtagova u tekstu, 100 objava u 24 h. Hub sve to provjerava prije poziva Grapha
i pokazuje upozorenja u pregledu.

Ugovor za stranice: [`social-feed-v1.md`](social-feed-v1.md), referentna implementacija
[`examples/laravel-social-feed/`](examples/laravel-social-feed/README.md).

## 10. AI agent i MCP (opcionalno)

`ANTHROPIC_API_KEY` u okolini uključuje `hub:agent-draft` — jutarnji prolaz koji piše tekstove za nove
kandidate i ostavlja ih na odobrenje. Uključuje se još i po brendu (Brend → Glas brenda → „AI piše
nacrte"); bez toga scheduler prolazi bez posla.

MCP server je na `https://hub.<domena>/mcp` iza Sanctum tokena. Token izdaješ s
`php artisan hub:issue-mcp-token <email> --scope=draft`; opseg određuje smije li agent samo čitati,
pisati nacrte, odobravati ili objavljivati. Detalji i primjer konfiguracije klijenta: [mcp.md](mcp.md).

## 11. Video, Reels i TikTok

`ffmpeg` mora postojati na stroju (`sudo apt-get install -y ffmpeg`); bez njega slike rade, a
`hub:render-video` padne tek kad ga netko pozove.

TikTok traži vlastite ključeve (`TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`,
`TIKTOK_REDIRECT_URI`), verificiranu domenu i prolazak audita — postupak je u [tiktok.md](tiktok.md).
Scheduler već vrti `hub:refresh-tiktok-tokens` svaki sat; bez povezanog TikTok računa taj prolaz ne
radi ništa.

## Čega nema

- Automatskog dohvata novih kataloga i oglasa izvan onoga što same stranice objavljuju.
- Statistike dosega i klikova iz mreža (hub bilježi samo je li objava izašla).
