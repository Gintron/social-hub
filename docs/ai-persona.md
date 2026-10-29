# AI lik (persona)

Brend može biti **AI lik** koji vodi svoje kanale (TikTok, Facebook, Instagram, YouTube) i priča o jednom području. Lik je
običan brend: ima iste račune, glas (kartica Voice-over), termine objave i dnevni limit. Na vrhu toga ima opis lika
(`brands.persona`, `App\Persona\Persona`), iz kojeg AI svako jutro predlaže teme, a iz odabrane teme piše skriptu shorta.

Hub ne zna ništa o području lika (novac, kuhanje, fitness…): to piše čovjek u opisu lika, u panelu. Nema koda po liku.

## Tok

1. **Brendovi → AI lik**: uključi „Ovaj brend je AI lik“ i opiši ga: tko je, cilj kanala, publika, ton, o čemu nikad ne
   priča, jezik (zadano engleski), izgled i ambijent (opis i slika), koliko tema dnevno i u koliko sati.
2. **Svako jutro** (`hub:suggest-topics`, satno u :05, radi kad dođe `suggest_at` po lokalnom vremenu brenda) OpenAI
   predloži teme za taj dan (`App\Actions\SuggestTopics` → `App\Persona\TopicSuggester`). Jednom dnevno: drugi prolaz isti
   dan ne pita ponovno, ni kad odgovor nije donio nijednu novu temu. Teme koje je lik imao u zadnjih 60 dana (`hub.persona.recent_days`, i odbačene) idu uz pitanje, a
   odgovor koji ipak ponovi naslov odbacuje se u kodu.
3. **Sadržaj → Teme AI likova**: „Odaberi“ (`ChooseTopic`) piše skriptu u pozadini (`WriteTopicScriptJob`). „Predloži teme“
   traži još jednu seriju odmah (`SuggestTopicsJob`).
4. **Stranica teme**: skripta redak po redak — što lik govori i kadar (`talking` = lik u kameru, `broll` = međukadar s opisom
   scene za video model), naslov za YouTube, tekst objave, hashtagovi, procjena trajanja. Spremi izmjene pa **Odobri
   skriptu** (`ApproveTopicScript`). Izmjena odobrene skripte vraća je na pregled (`UpdateTopicScript`).

Stanja teme (`App\Enums\TopicStatus`): prijedlog → piše se skripta → skripta čeka pregled → skripta odobrena; ili
odbačeno / skripta nije uspjela (s razlogom; tema se može odabrati ponovno).

## Pravila koja drži upit (i čovjek)

- Ništa izvan područja i cilja lika, ništa s popisa „nikad ne priča“.
- Bez izmišljenih statistika, studija, citata, cijena i vijesti; bez savjeta koji gledatelju mogu naškoditi.
- Lik je otvoreno AI: nikad ne tvrdi da je nešto proživio („probala sam“, „moja djeca“).
- Tekst objave ne kaže ništa što skripta ne kaže, i nema poveznica.

Upit je prvi filtar, čovjek koji čita skriptu drugi, a `CaptionValidator` treći: iznos, postotak ili poveznica u tekstu
objave koje skripta ne kaže zaustavljaju video prije plaćanja.

## Video (faza 2)

Na stranici odobrene skripte **Napravi video** (`ProduceShort` → `ProduceShortJob`, red `render`) pokazuje procjenu
trajanja i cijene na falu i, nakon potvrde, radi short u pozadini (`App\Persona\ShortProducer`):

1. **Provjere prije ikakvog plaćanja**: tekst objave ne smije reći iznos (€, $, £, kn), postotak ni poveznicu koje skripta
   ne kaže (`CaptionValidator`); izgovoreni redci prolaze `ScriptGuard` (nijedna web adresa naglas, duljina do
   `hub.persona.max_characters`); lik ima barem jedan aktivan kanal koji prima video; postoji slika lika ako skripta ima
   kadrove u kojima lik govori; procjena nije iznad `fal.max_cost_per_video`.
2. **Glas** (`ShortVoice`): svaki redak je jedan isječak iz ElevenLabsa, glasom brenda (kartica Voice-over). Brojeve, iznose,
   postotke, vremena i godine ispisuje hub (`SpokenEnglish` za engleski, `SpokenCroatian` za hrvatski), nikad model.
   Popis izgovora i IPA riječi brenda vrijede kao i za ostale videe. Redak izgovoren prije ne plaća se ponovno.
3. **Kadrovi**: uzastopni redci istog kadra su jedan kadar. „Lik govori“ je fal model koji usne vodi **našim** zvukom
   (`fal.models.talking`, zadano Kling AI Avatar v2 Standard: slika lika + zvuk tih redaka), „međukadar“ je fal model koji
   radi scenu iz opisa (`fal.models.broll`, zadano Kling v3 Standard text-to-video, 9:16, 3–15 s, bez vlastitog zvuka).
4. **Spajanje** (`ShortComposer`): kadrovi jedan za drugim u 1080×1920 (izrezani da pokriju kadar, zadnji kadar se drži ako
   je isječak kraći od redaka), glas preko njih, glazba brenda ispod glasa (stišava se kad lik govori), titlovi po nekoliko
   riječi (Figtree, donja trećina). Izlaz H.264/yuv420p + AAC + `faststart`.
5. **Objava** kroz `CreateVideoPost` (isti put kao gotov video): kanali lika koji primaju video, tekst objave + hashtagovi
   iz skripte (YouTube: naslov u prvom retku, `#shorts`), oznaka AI uvijek, termin = sljedeći prozor objave, **čeka
   odobrenje**. Tema prelazi u „Video je u objavi“ i ima gumb „Otvori objavu“.

**Ništa se ne plaća dvaput.** Svaki zahtjev falu zapisuje se u `topic_ideas.production` čim je poslan. Worker zaustavlja
posao nakon 180 s, a fal radi minutama, pa posao ne čeka: napravi što može, pita fal je li gotov i vraća se za
`fal.poll_seconds` (najdulje `fal.max_wait_minutes` od zahtjeva). Preuzimanje kadrova i spajanje videa nikad nisu u istom
prolazu (spajanje ima svoj, uz `veryfast` x264), a glas kadra i gotov video se spremaju i ne rade se ponovno. Kad se video
traži ponovno (nakon greške ili izmjene jednog retka), kadar čiji se tekst, opis, glas, model i slika lika nisu
promijenili ne radi se ponovno. Zahtjev od kojeg je fal sam odustao briše se, pa idući pokušaj šalje novi.

Razlog neuspjeha piše na stranici teme („Video nije uspio“); „Pokušaj video ponovno“ koristi sve što je već plaćeno.

Nije provjereno uživo: nijedan stvarni poziv falu (Marijan je fal koristio na drugom projektu). Cijene u `config/fal.php`
su falove objavljene cijene i služe za procjenu; fal ne javlja cijenu zahtjeva.

## Postavke

- `config/openai.php` → `persona` (`OPENAI_PERSONA_MODEL`, `_EFFORT`, `_MAX_OUTPUT_TOKENS`, `_TIMEOUT`); prazno = isto kao
  za tekstove objava.
- `config/hub.php` → `persona.recent_days`, `persona.max_seconds` (koliko skripta smije trajati izgovorena, zadano 60),
  `persona.words_per_second` (za procjenu trajanja), `persona.max_characters` (najviše znakova glasa po shortu, 1200).
- `config/fal.php` → `FAL_KEY`, modeli (`FAL_TALKING_MODEL`, `FAL_BROLL_MODEL`), cijene po sekundi, `FAL_MAX_COST_PER_VIDEO`,
  `FAL_POLL_SECONDS`, `FAL_MAX_WAIT_MINUTES`.

```bash
./vendor/bin/sail artisan hub:suggest-topics --brand=mia
./vendor/bin/sail artisan hub:suggest-topics --brand=mia --more
```
