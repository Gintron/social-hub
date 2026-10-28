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
   dan ne pita ponovno. Teme koje je lik imao u zadnjih 60 dana (`hub.persona.recent_days`, i odbačene) idu uz pitanje, a
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

Upit je prvi filtar, čovjek koji čita skriptu drugi. Kad tekst objave krene van (faza 2), ide i kroz provjeru kao i drugi
tekstovi: iznos ili postotak koji skripta ne sadrži ne smije u objavu.

## Što još nije napravljeno (faza 2)

Iz odobrene skripte: glas (ElevenLabs, glas lika iz kartice Voice-over, brojevi na engleskom kroz hub kao `SpokenCroatian`
za hrvatski), video preko **fal.ai** (lik koji govori: model koji usne vodi našim zvukom, npr. Kling AI Avatar; međukadrovi:
video model iz opisa scene), spajanje u 1080×1920 i nacrt na kanalima lika s oznakom AI, zakazan u termin objave. Ništa iz
faze 1 ne troši ništa osim OpenAI teksta.

## Postavke

- `config/openai.php` → `persona` (`OPENAI_PERSONA_MODEL`, `_EFFORT`, `_MAX_OUTPUT_TOKENS`, `_TIMEOUT`); prazno = isto kao
  za tekstove objava.
- `config/hub.php` → `persona.recent_days`, `persona.max_seconds` (koliko skripta smije trajati izgovorena, zadano 60),
  `persona.words_per_second` (za procjenu trajanja).

```bash
./vendor/bin/sail artisan hub:suggest-topics --brand=mia
./vendor/bin/sail artisan hub:suggest-topics --brand=mia --more
```
