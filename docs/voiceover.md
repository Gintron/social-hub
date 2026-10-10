# Voice-over (ElevenLabs, izgovor riječi u IPA)

Videi koje hub sam slaže (Reels i TikTok) mogu imati glas koji izgovara ono što je na slajdovima:
što je ponuda ili posao, koliko košta, koliki je popust, do kada vrijedi i što brend traži od
gledatelja. Uključuje se jednom, na brendu; dalje hub sve radi sam — od pisanja teksta do miksa —
i urednik ne mora ništa dirati. **ElevenLabs** izgovara. Riječi koje glas griješi (npr. „letka“) brend ima
na popisu s izgovorom u IPA-i, a hub ga umeće u tekst koji glas čita. **OpenAI** ne odlučuje što se
izgovara: predlaže IPA jedne riječi u panelu (✨), a sam izgovor u videu ide samo s popisa koji je čovjek
potvrdio uhom. Automatski izbor riječi od OpenAI-ja postoji, ali je zadano isključen (vidi *Eksperiment*).

## Uključivanje

1. **Ključevi.** U okolinu poslužitelja (Ploi → Environment) dodaj `ELEVENLABS_API_KEY`. `OPENAI_API_KEY`
   treba samo prijedlogu izgovora (✨) i eksperimentu; isti OpenAI ključ piše i tekstove objava
   (`hub:agent-draft`). ElevenLabs ključ smije biti ograničen: treba mu pravo za text-to-speech, a za popis
   glasova i pregled potrošnje u panelu i pravo čitanja glasova i korisnika (bez toga hub radi, samo ID glasa
   zalijepiš ručno). Deploy skripta već radi `queue:restart`, pa novi ključ stiže do workera.
2. **Glas.** Brendovi → *Voice-over (ElevenLabs)* → odaberi glas (★ = ElevenLabs ga navodi kao
   provjerenog za hrvatski) i uključi *Videi ovog brenda dobivaju voice-over*.
3. **Riječi s ručnim izgovorom.** Brendovi → Voice-over → *Riječi s ručnim izgovorom (IPA)*: riječ („letka“)
   i njezin IPA (`ˈlɛtka`). Gumb ✨ predloži IPA (OpenAI), a izgovor doradi uhom (vidi *Izgovor riječi (IPA)*).
4. **Preslušaj.** Gumb *Preslušaj glas* izgovori rečenicu s postavkama iz obrasca (i onima koje još
   nisu spremljene) i prikaže kako je hub napisao brojeve i riječi s IPA-om. Prekidač *Bez IPA-a* izgovori isti
   tekst bez ijedne riječi s izgovorom, za usporedbu. Isto s naredbom:

   ```bash
   ./vendor/bin/sail artisan hub:voiceover-test --brand=uselisto
   ./vendor/bin/sail artisan hub:voiceover-test "Kruh 500 g za 1,49 €" --voice=<ID>
   ./vendor/bin/sail artisan hub:voiceover-test --brand=uselisto --word="letka=ˈlɛtka" --compare
   ./vendor/bin/sail artisan hub:voiceover-test --list-voices
   ```
   `--word` isproba riječ s IPA-om prije nego je spremiš (popis brenda se ne mijenja; isječak se ipak sprema u keš kao svaki izgovor). `--ipa=tag|slash|bare|off` mijenja
   zapis za jedno pokretanje, a `--compare` izgovori istu rečenicu bez IPA-a i sa svakim zapisom, da se čuje koji
   glas najbolje razumije.
5. **Provjera.** `hub:doctor` javlja ima li ključeva (OpenAI pita za modele koje će koristiti, ElevenLabs za
   plan), koliko je plana ostalo, koji brend traži glas koji ne može imati i koji brend ima riječi s IPA-om
   koje njegov model ne čita.

Od tog trenutka svaki novi video tog brenda — pojedinačna objava, pregled („Top 3 akcije“), serija —
dobiva glas. Već izrađeni videi ostaju kakvi jesu dok ih netko ne renderira ponovno.

## Tko odlučuje ima li video glas

Redom, prvo što postoji:

1. **Kanal** (`settings.voiceover` = `on`/`off`): prekidač *Voice-over* na kanalu u pregledu nacrta.
   Promjena renderira video tog kanala ponovno; ostali kanali ostaju kakvi jesu.
2. **Pravilo automatske objave** (Izvori → Automatska objava → *Voice-over*): *Uključen*, *Isključen*
   ili prazno. Vrijedi i za pregledne serije, koje se slažu iz pravila kanala.
3. **Brend** (*Videi ovog brenda dobivaju voice-over*).

Glas imaju samo videi (Reel, TikTok). Slika i carousel nemaju zvuk. Brend bez odabranog glasa ili hub
bez ključa uvijek radi video kakav je bio prije — nedostatak glasa nikad ne zaustavlja objavu.

## Što glas govori

Tekst se **piše iz podataka stavke**, istim poljima od kojih se crta slajd (`Highlights`, `price`,
`facts`), a ne izmišlja. Svaki slajd govori ono što pokazuje, ne više:

| Slajd | Govori |
|---|---|
| Akcija, udica | „Kruh bijeli 500 g u trgovini Konzum za 1,49 €.“ |
| Akcija, kartica | „Popust 25 %. Vrijedi do 30.09.2026.“ (stara cijena se ne čita: na slajdu je, a košta tri sekunde) |
| Posao, udica | „Konobar/ica. Satnica: 7.00 – 8.00 €/H. Lokacija: Split.“ |
| Posao, kartica | „Poslodavac: Hotel Adriatic. Sezonski posao, smještaj i obrok.“ |
| Usporedba | „Mljevena kava 500 g. Najjeftinije: Lidl, 9,98 €/kg.“ + „Zatim Kaufland …“ |
| Pregled, naslovnica | Naslov pregleda i „Popusti do 40 %.“ |
| Pregled, kartica | Proizvod, cijena, popust (trgovina samo ako je pregled miješan) |
| Završni slajd | Rečenica brenda: *Završna rečenica* ili poziv na akciju + prvi korak iz „Glas brenda“ |

Slajd bez ičega novog za reći ostaje uz glazbu.

**Iznosi su provjereni.** Prije izgovora svaki iznos, postotak i poveznica prolaze isti `CaptionValidator`
kao tekst objave: što stavka ne navodi, ne izgovara se (`ScriptGuard`). Skripta iz stavke uvijek prolazi;
provjera postoji za redak koji je urednik izmijenio. Izuzeti su završna rečenica i naslov pregleda
(„Akcije do 50 % popusta“): to je tekst koji je brend sam napisao i koji stoji na naslovnici i u tekstu
objave kakav jest; ostatak retka i dalje se provjerava. Glas nikad ne čita web adresu.

**Brojeve izgovara hub, ne model** (`SpokenCroatian`). „1,49 €“ postaje „jedan euro i četrdeset devet
centi“, „7,00 – 8,00 €/H“ „od sedam do osam eura po satu“, „−25 %“ „minus dvadeset pet posto“,
„500 g“ „petsto grama“, „30.09.2026.“ „tridesetog rujna“, „Konobar/ica“ „konobar ili konobarica“, uz
padeže i rod („dvije akcije“, „dva oglasa“). Model dobiva samo riječi, pa cijena ne ovisi o tome kako je
danas odlučio pročitati brojku.

**Imena.** Brendovi → Voice-over → *Izgovor imena* (npr. `DM` → `de em`). Zamjena vrijedi za cijeli
tekst prije nego ga glas čuje, pa može biti i fraza: `u trgovini Konzum` → `u Konzumu` daje padež koji hub
sam ne zna izvesti (ime lanca je podatak stranice, ne hubovo znanje). VELIKA SLOVA od četiri slova naviše
(`LOVRAN`, `SPAR`) hub pretvara u ime; kratice (`TV`, `PDV`, `USB`) čita slovima. Izgovor imena ide **prvi**,
prije IPA-a (vidi *Redoslijed*).

### Izmjena teksta

*Renderiraj ponovno* na video kanalu pokazuje što glas govori, redak po slajdu. Izmijeni samo ono što
glas čita krivo; prazan redak ostavlja slajd uz glazbu. Ako ništa ne izmijeniš, tekst se ponovno piše
iz stavke (pa se promijenjena cijena na izvoru ne zamrzne). Ispod videa stoji što je glas rekao.

## Redoslijed: od stavke do glasa

Svaki redak (slajd) prolazi ovim putem, istim redom za video, za pregled, za katalog i za probni zapis u panelu:

1. **Tekst** — `ScriptBuilder` iz stavke ili redak koji je urednik napisao; `ScriptGuard` provjerava iznose i
   poveznice prema stavci.
2. **Izgovor imena** — `SpokenCroatian::speak`, prvo: riječi s popisa *Izgovor imena* zamijene se ovim što brend
   traži. Ostatak `SpokenCroatian`-a (brojevi, iznosi, datumi, kratice, mjere) ide nakon toga, na već
   zamijenjenom tekstu.
3. **Izgovor riječi (IPA)** — `Phonetizer::prepare`, na izgovorenom tekstu: ručne riječi s popisa brenda, pa
   (samo ako je uključen *OpenAI predlaže izgovor i za ostale riječi*) riječi koje model dopiše. Zapis u tekstu
   je `tag`, `slash`, `bare` ili `off`.
4. **Glas** — `Synthesizer`: ključ isječka je sha256 teksta *nakon* koraka 3, glasa, modela i postavki. Isti
   tekst istim glasom plaća se jednom; redak s IPA-om ima svoj ključ, a završna rečenica brenda se ponavlja u svakom videu.
5. **ElevenLabs** — `eleven_v4` dobiva tekst kakav jest ili s IPA-om. Model koji nije na popisu `ipa_models`
   dobiva tekst bez IPA-a (vidi *Izgovor riječi (IPA)*).

**Pravilo prvenstva.** Riječ koju *Izgovor imena* daje glasu (ono s desne strane, `say`) ne dobiva IPA ni s
popisa ni od modela, jer je ono što je izgovor imena dao već ono što glas čuje. Testovi:
`PhonetizerTest` i `NarratorTest` (*a word the pronunciation list says …*). Ako riječ s popisa *Izgovor imena*
nestane iz teksta (zamijenjena je), njezin IPA nema gdje stati i ne primjenjuje se.

**Ograničenje tog pravila.** Riječ koju izgovor imena daje glasu isključena je iz IPA-a za **cijeli video**, pa
i njezina neizmijenjena pojavljivanja u drugom retku ne dobiju IPA. To je namjerno (dvostruka zamjena je gora od
izostavljenog izgovora), ali je rijetko: brend mora imati riječ i u *Izgovor imena*-u i na popisu IPA-a.

## Izgovor riječi (IPA)

Glas koji čita hrvatski tu i tamo krivo izgovori riječ: rijetku, ime, posuđenicu, riječ koja se piše jednako kao
neka druga („letak“ i „letka“). Brend zato ima **popis riječi s izgovorom**: Brendovi → Voice-over → *Riječi s ručnim
izgovorom (IPA)*; redak je riječ („letka“) i njezin IPA (`ˈlɛtka`, naglašeni slog iza znaka ˈ). Hub taj IPA umetne u
tekst koji ElevenLabs izgovara, u svakom retku u kojem ta riječ stoji. **IPA bira čovjek, uhom**; to je ono što je u Listo
TikTok reklami zvučalo bolje od običnog teksta (tada kroz ElevenLabsov rječnik, sada izravno u tekstu).

- **Gdje i kako.** Uz polje IPA je gumb ✨: **OpenAI predloži IPA** za upisanu riječ (npr. `letka → ˈlɛtka`,
  `letak → ˈlɛtak`), a ti ga doradiš preslušavanjem. Apostrof hub sam pretvara u ˈ, a kose ili uglate zagrade skida.
  Isti IPA ide u svaki video brenda; nema ništa za održavati osim popisa (do 200 riječi).
- **Točan oblik riječi, svejedno koje veličine slova.** „Letka“ na početku rečenice je ista riječ kao „letka“, a pravilo za
  „letka“ ne dira „letku“ ni „letkama“: **svaki padežni oblik je zasebni redak** (letak, letka, letku, letkom, letci …).
  Hub ne izvodi nastavke niti ih predlaže. Riječ mora biti jedna, samo slova.
- **Zapis u tekstu** (*Zapis izgovora (IPA)*, zadano `VOICEOVER_IPA=tag`):

  | Način | Što glas dobije | Dodatnih znakova (plaća se po znaku) |
  |---|---|---|
  | `tag` | `<phoneme alphabet="ipa" ph="ˈlɛtka">letka</phoneme>`: riječ ostaje, izgovor uz nju | oko 45 po takvoj riječi |
  | `slash` | `/ˈlɛtka/` umjesto riječi | 2 po takvoj riječi |
  | `bare` | `ˈlɛtka` umjesto riječi | 0 |
  | `off` | tekst kakav jest | 0 |

  Plaća se samo uz riječi s popisa (nekoliko riječi u nekoliko redaka), pa je `tag` zadan: najbliži je rječniku koji je
  zvučao bolje. Koji zapis zvuči najbolje čuje se samo na uho: `hub:voiceover-test --word="letka=ˈlɛtka" --compare`.
- **Što smije biti IPA.** Samo slova i znakovi IPA-e, bez razmaka, navodnika i oznaka, najviše jedan primarni naglasak i
  barem jedan samoglasnik, do 60 znakova (`Ipa::sanitize`): ono što stoji u `<phoneme ph="…">` ne smije ga moći prekinuti.
  Što je čovjek upisao ne uspoređuje se s riječi (smije je i prepisati, i čuje se).
- **Model v4 jedini čita IPA u tekstu.** `elevenlabs.ipa_models` = `eleven_v4`: `eleven_v4` izgovara IPA umetnut u rečenicu u
  sva tri zapisa (provjereno živo 2. 10. 2026. transkripcijom izgovora; što to zvuči kako, potvrđuje samo uho). Drugi model
  dobiva tekst bez IPA-a, pa riječi s popisa za njega nemaju učinka. Na njega se IPA ne šalje dok ga netko ne preslušaš i ne
  doda u `ipa_models`. Brend čija je spremljena postavka izvan popisa dobiva zadani model, pa se ponaša kao v4. Panel i
  `hub:doctor` kažu kad brend ima riječi s IPA-om, a model ih ne čita.
- **Ponovljena uporaba.** Riječ s popisa ide u svaki video ovog brenda. Nema ElevenLabsova rječnika, sinkronizacije ni
  arhiviranja: samo popis koji čovjek održava.

### Što model smije (OpenAI, prijedlog i eksperiment)

- **Prijedlog jedne riječi** (`IpaSuggester`, gumb ✨ u panelu): model dobije jednu riječ (i, ako je ima, rečenicu) i vrati
  njezin IPA. Odgovor prolazi `Ipa::clean`, koji odbacuje sve što nije transkripcija **te** riječi (duljina do 2 ×
  duljina riječi + 4, mora nalikovati riječi, ne smije biti sama riječ): IPA za „mačku“ dan za „listu“ pada. Što je
  prijedlog, čovjek potvrđuje uhom prije spremanja. Pogodio je Marijanov `ˈlɛtka`/`ˈlɛtak`.
- **Eksperiment: model bira riječi** (`VOICEOVER_IPA_AUTO`, zadano `false`; brend: *OpenAI predlaže izgovor i za ostale
  riječi*). Svaki redak prolazi kroz `Phonetizer::marks`, koji za riječi koje glas može izgovoriti krivo traži IPA; odgovor
  se sprema u `voiceover_transcriptions` (ključ: redak, model i verzija uputa), pa isti redak u svakom videu dobiva isti
  izgovor i već plaćeni isječak se nalazi. Cijeli video ide jednim zahtjevom, i to samo za retke koji još nisu
  transkribirani. Riječi s popisa imaju prednost pred modelom. Riječi koje *Izgovor imena* daje glasu model može
  transkribirati, ali se njihov izgovor ne primjenjuje (vidi *Redoslijed*). Ako OpenAI ne
  odgovori, video izlazi bez glasa (`ipa_*`, tablica dolje).

### Što je pokazao pravi test (2. 10. 2026.)

S pravim ključevima (`hub:voiceover-test --compare`, glas Ana, v4) OpenAI je za rečenicu „Preuzmi Listo
besplatno i dodaj prvi proizvod s letka. Kruh bijeli … u trgovini Konzum …“ transkribirao samo **Listo** (`ˈlistɔ`) i
**Konzum** (`ˈkɔnzum`); „letka“, riječ zbog koje je sve počelo, nije dirao. Marijanu je **obični tekst (bez IPA-a) zvučao
najbolje**, a stroj (ElevenLabs STT) je kod `slash` i `bare` čuo „listu“ umjesto „listo“. Nasuprot tome, u Listo TikTok
reklami IPA koji je **ručno odabran za riječi koje glas griješi** (rječnik `listo-izgovor-ipa`: letak, letka) zvučao je bolje
od običnog teksta. Zaključak: IPA pomaže kad je za pravu riječ, a model ne pogađa koje su to; zato riječi bira čovjek, a
automatski izbor modela je isključen. (OpenAI jest dobro pogodio **IPA** kad mu se riječ zada: za „letka“ i „letak“ predložio
je točno `ˈlɛtka` i `ˈlɛtak`; zato služi kao prijedlog uz polje.)

## Zvuk i vrijeme

- **Slajd čeka riječi.** Slajd ostaje dok se njegov redak ne izgovori (0,2 s prije, 0,4 s poslije, uz
  prijelaze) i nikad kraće nego bez glasa. Video je zato nešto duži: tipično 12–16 s umjesto 9 s.
- **Brzina** je zadano 1,05 (postavka brenda, 0,7–1,2), ali **v4 je ne primjenjuje**: isti redak od 43 znaka
  vratio se jednako dug (59 812, 58 558 i 59 812 bajtova) uz brzinu 0,7, 1,0 i 1,2, a toliko se razlikuju i dva
  identična zahtjeva (izmjereno 2026-10-03). API je prihvaća (200), pa postavka ne smeta, ali ne ubrzava.
  Ubrzati se može samo u hubu (ffmpeg `atempo`); to nije napravljeno. Redak dulji od 130 znakova gubi zadnje
  rečenice; naslov se reže na 60 znakova.
- **Svaki redak ide zasebnim zahtjevom** i dovodi se na istu glasnoću (−14 LUFS, kako se kratki video
  obično miksa: feedovi ga ne normaliziraju pa tih video zvuči slabije) prije miksa, pa glas ne skače
  između slajdova. Na kraju miksa je limiter na −1 dBFS koji skida vrhove govora.
- **Glazba** brenda spušta se ispod glasa (*Glasnoća glazbe ispod glasa*: tiho −20 dB, srednje −14 dB,
  glasnije −8 dB) i dodatno stišava dok glas govori, pa se vrati. Bez glazbe je glas sam.
- Izlaz je i dalje H.264/yuv420p + AAC 44,1 kHz + `faststart`.

## Cijena i keš

ElevenLabs naplaćuje po znaku. Svaki izgovoreni redak sprema se u `voiceovers` (tekst, glas, model,
postavke → sha256) i **isti tekst istim glasom ne plaća se dvaput**: završna rečenica brenda izgovori se
jednom i koristi u svakom idućem videu; ponovni render, prebacivanje kanala s Reela na TikTok ili
isključivanje i uključivanje glasa ne koštaju ništa. Tablica je i knjiga: što je rečeno, kojim glasom,
koliko je bilo znakova (`characters`) i koliko je ElevenLabs naplatio (`cost`). Ključ je tekst **s umetnutim IPA-om**,
pa se izmjena zapisa (`slash` → `tag`) ili popisa plaća kao novi tekst, i to samo za retke koji se ponovno izgovaraju.
Izgovor je dio teksta, ne posebna postavka: nema rječnika čija bi verzija ulazila u ključ.

**`characters` i `cost` nisu isto.** `characters` je duljina izgovorenog teksta (`mb_strlen`, hub je računa
sam). `cost` je zaglavlje odgovora `character-cost`: dokumentacija ga opisuje kao „cijenu generiranja u
znakovima“, ali izmjereno je drugo, ono što je zahtjev naplaćen u kreditima računa, i ovisi o modelu. Hub govori
samo modelom v4 (vidi *Ograničenja*); na njemu (2026-10-03, plan `payg`) redci od 10, 43, 87 i 219
znakova dali su 1, 3, 5 i 13 kredita, dakle oko 0,06 kredita po znaku. Isti redak od 43 znaka na drugim modelima
koštao je 14 (multilingual v2), 9 (v3) i 7 (flash v2.5), pa je pri prelasku na noviji model prvo što treba
napraviti `hub:voiceover-test` i pogledati stupac *Kredita*. Zbroj zaglavlja slaže se približno s dnevnom
potrošnjom u kreditima (`GET /v1/usage/character-stats?metric=credits`). `cost` je `null` kad API zaglavlje nije
poslao; duljinu teksta nikad ne glumi. Za koliko je koji brend potrošio zbroji `cost`, ne `characters`.

Na `payg` planu `character_count` iz `/v1/user/subscription` (to je ono što `hub:doctor` i panel zovu
„koliko je plana ostalo“) nije se pomaknuo ni nakon osam poziva u nekoliko minuta (izmjereno 2026-10-03), pa
nemoj po njemu zaključivati da ništa nije potrošeno; dnevna potrošnja je u `character-stats`.

Procjena: oko 250 znakova po videu, 10 videa dnevno ≈ 75 000 znakova mjesečno, a to je na v4 oko 4 500 kredita. Gornja
granica po videu je `ELEVENLABS_MAX_CHARACTERS_PER_VIDEO` (800; oko minute govora, a Facebook Reel smije 90 s); mjeri se
duljinom skripte (`ScriptGuard`), ne zaglavljem. IPA u tekstu dodaje znakove (tablica iznad): `tag` oko 45 po riječi, pa se
ograda računa na tekst koji glas dobije.

`hub:prune-voiceovers` (tjedno, nedjeljom) briše zvučne datoteke starije od 60 dana; zapisi ostaju, a
ista rečenica, ako zatreba, izgovori se ponovno pod istim zapisom.

**Dvije tablice: nova i stara.** `voiceover_transcriptions` je odgovor modela po retku (`Phonetizer`, samo *Eksperiment*).
`voiceover_accents` je tablica starog mehanizma, naglasaka iz OpenAI-ja, i **nitko je više ne čita ni piše** (model
`VoiceoverAccent` je maknut). Migracija je ne briše: brisanje u produkciji je nepovratno, a ništa ne dobiva. Može se
ukloniti ručno kad se potvrdi da joj nema potrebe. `2026_10_10_000001_create_voiceover_transcriptions_table` stvara
`voiceover_transcriptions` samo ako ne postoji.

## Kad glas ne uspije

Glas je dodatak videu, nikad njegov uvjet. Nema ključa, plan je potrošen, glas više ne postoji,
ElevenLabs ili OpenAI (izgovor) nisu dostupni ili je tekst odbijen — video se renderira **bez glasa**
(uz glazbu, ako je ima) i izlazi na vrijeme. Razlog se sprema uz video (`params.voiceover`) i piše na kanalu u pregledu
(*Video je bez voice-overa: …*); admin dobiva **jedan** e-mail po uzroku na 12 sati.

| Šifra | Znači | Što napraviti |
|---|---|---|
| `not_configured` | Nema ključa ili glasa | `ELEVENLABS_API_KEY`, odabir glasa |
| `invalid_key` / `missing_permissions` | Ključ ne vrijedi ili nema pravo | Novi ključ, pravo *Text to Speech* |
| `quota_exceeded` | Plan je potrošen | Pričekaj obnovu ili nadogradi plan |
| `voice_not_found` | Glas je obrisan | Odaberi glas ponovno |
| `unavailable`, `rate_limited` | ElevenLabs zapinje | Hub sam pokuša četiri puta (do oko 20 s čekanja); ništa |
| `script_rejected` | Redak ne prolazi provjeru | Ispravi redak u dijalogu |
| `ipa_not_configured` | Brend ima uključen „OpenAI predlaže izgovor i za ostale riječi“, a nema `OPENAI_API_KEY` | Postavi ključ ili isključi taj prekidač na brendu |
| `ipa_invalid_key` / `ipa_forbidden` | OpenAI ključ ne vrijedi ili projekt ne smije koristiti model | Novi ključ; dopusti model u OpenAI projektu |
| `ipa_quota_exceeded` | Na OpenAI računu nema kredita | Dopuni Billing |
| `ipa_model_not_found` | Ime modela ne postoji | `OPENAI_IPA_MODEL` / `OPENAI_MODEL`, provjeri s `hub:doctor` |
| `ipa_unavailable`, `ipa_rate_limited` | OpenAI zapinje | Hub sam pokuša četiri puta; ništa |
| `ipa_incomplete`, `ipa_invalid_output`, `ipa_refused`, `ipa_rejected` | Model nije vratio upotrebljiv odgovor | Renderiraj ponovno; ako se ponavlja, isključi „OpenAI predlaže izgovor i za ostale riječi“ |

Kad se uzrok ukloni, *Renderiraj ponovno* dodaje glas (video bez glasa se ne koristi ponovno za kanal koji
ga traži).

## Ograničenja i što provjeriti

- **Pozivi prema ElevenLabsu i OpenAI-ju nisu isprobani živim ključem u razvoju.** ElevenLabs zahtjev je
  napisan po njihovoj dokumentaciji (`POST /v1/text-to-speech/{voice_id}`, zaglavlje `xi-api-key`,
  `voice_settings`, `mp3_44100_128`) i pokriven testovima s lažnim odgovorom koji je pravi MP3. OpenAI zahtjev
  je Responses API sa strogom JSON shemom (`text.format` `json_schema`, `strict`) i isto je pokriven lažnim
  odgovorima. Prvo što treba napraviti s pravim ključevima je `hub:doctor` i `hub:voiceover-test --compare`.
- **Nije provjereno uhom kako v4 čita IPA u tekstu.** Transkripcija (2. 10. 2026.) potvrđuje da izgovor stiže, ne kako
  zvuči. Jedino što to može potvrditi je uho: `hub:voiceover-test --compare` s riječima s popisa. Ako nijedan zapis ne
  zvuči bolje od običnog teksta, postavi `off` (brend ili `VOICEOVER_IPA=off`) i glas se izgovara kakav jest.
- **Točnost izgovora je točnost modela ili čovjeka.** Kod provjerava da je IPA transkripcija te riječi, ne da je izgovor
  dobar. Prijedlog OpenAI-ja zato uvijek ide kroz uho prije spremanja.
- **Jedini model je `eleven_v4`** (Marijan, 03. 10. 2026.), a kad ElevenLabs izda noviji, njega. Model je
  `ELEVENLABS_MODEL` i popis `models` u `config/elevenlabs.php`, a IPA se šalje samo modelima iz `ipa_models`
  (sada samo v4). U kodu nema grane po imenu modela. Novi model: dodaj ga u popis, preslušaj
  `hub:voiceover-test --compare` i pogledaj stupac *Kredita*; tek onda ga dodaj u `ipa_models`. Kvaliteta hrvatskog ovisi o
  glasu i modelu. Brojeve izgovara hub (`SpokenCroatian`), ne model.
- **Oznaka umjetnog sadržaja.** Glas je sintetiziran. TikTok i Meta imaju pravila o označavanju
  AI-generiranog sadržaja i hub ih ne postavlja sam (nije provjereno postoji li u TikTok Business
  API-ju parametar za tu oznaku). Pročitaj njihova pravila prije nego uključiš glas na javnim računima.
- **Licenca glasa.** Komercijalna upotreba traži plaćeni ElevenLabs plan; neki glasovi iz biblioteke imaju
  vlastite uvjete.
- **ffmpeg 4.4 ili noviji** (`amix` s opcijom `normalize`); `hub:doctor` to provjerava.

## Okolina (env)

Sve su zadane vrijednosti sigurne: bez ključeva nema glasa, a video se renderira kao i prije.

| Varijabla | Zadano | Što radi |
|---|---|---|
| `ELEVENLABS_API_KEY` | prazno | Glas. Bez njega nijedan video nema glas, a ostalo radi. |
| `ELEVENLABS_MODEL` | `eleven_v4` | Zadani model; brend ga može promijeniti na popisu modela. |
| `ELEVENLABS_MAX_CHARACTERS_PER_VIDEO` | `800` | Gornja granica znakova po videu (vidi *Cijena i keš*). |
| `VOICEOVER_IPA` | `tag` | Zapis IPA-a za brend koji ga nije odabrao: `tag`, `slash`, `bare` ili `off`. |
| `VOICEOVER_IPA_AUTO` | `false` | Eksperiment: model bira i piše IPA za riječi koje nisu na popisu. Traži OpenAI ključ. |
| `OPENAI_API_KEY` | prazno | Prijedlog izgovora (✨) i eksperiment. Tekstovi objava (`hub:agent-draft`) koriste isti ključ. |
| `OPENAI_MODEL` | `gpt-6-sol` | Model za tekstove; izgovor ga dijeli ako nema svoj. |
| `OPENAI_EFFORT` | `medium` | Razmišljanje za tekstove; izgovor ga dijeli ako nema svoje. |
| `OPENAI_IPA_MODEL` | prazno (= `OPENAI_MODEL`) | Model za izgovor (prijedlog i eksperiment). |
| `OPENAI_IPA_EFFORT` | prazno (= `OPENAI_EFFORT`) | Razmišljanje za izgovor. |
| `OPENAI_IPA_MAX_OUTPUT_TOKENS` | `12000` | Najveći odgovor za izgovor (uključuje razmišljanje). |
| `OPENAI_IPA_TIMEOUT` | `60` | Sekunde čekanja na izgovor. |

Nikad ne upisuj ključeve u dokumentaciju ili u repozitorij; `.env.example` nosi samo prazne vrijednosti i nazive.

## Video „izašao je novi katalog“

Ima vlastiti scenarij od četiri rečenice (`App\Catalog\CatalogCopy`) umjesto teksta po slajdu, ali isti put
do glasa: `Narrator::narrateItems` → provjera → `SpokenCroatian` (izgovor imena, pa brojevi) → IPA (`Phonetizer`) →
`Synthesizer` s ključem u `voiceovers`. Rečenice 2–4 su iste za sve lance, pa se plaćaju jednom; prva nosi lanac
(„Konzumov katalog“). Glas ne ruši render, video bez glasa je isti video (vidi `catalog-video.md`).

## Gdje je u kodu

```
app/Voiceover/SpokenCroatian   izgovor imena (pronounced), pa brojevi, iznosi, postoci, jedinice, datumi → riječi
app/Voiceover/ScriptBuilder    piše tekst po slajdu iz stavke (Highlights, price, facts)
app/Voiceover/ScriptGuard      iznosi i poveznice moraju biti iz stavke (CaptionValidator)
app/Voiceover/Ipa              IPA kao podatak: što je riječ, što je zapis (sanitize, clean, render: tag, slash, bare, off)
app/Voiceover/Phonetizer       riječi s popisa + (ako je uključeno) model piše IPA; `prepare` je jedini ulaz za glas
app/Voiceover/IpaSuggester     jedan prijedlog IPA-a za riječ na formi (✨); nikad ne upisuje sam
app/Models/VoiceoverTranscription  odgovor modela po retku, kao što je zapisan (`voiceover_transcriptions`)
app/Ai/OpenAiClient            Responses API sa strogom JSON shemom; zajednički klijent za tekstove i izgovor
app/Voiceover/ElevenLabsClient HTTP klijent, mapiranje grešaka, popis glasova, potrošnja
app/Voiceover/Synthesizer      jedan redak → jedan isječak, keš u `voiceovers`, trajanje i glasnoća
app/Voiceover/Narrator         tekst → provjera → izgovor imena → IPA → isječci po slajdu
app/Rendering/ScenePlanner     slajdovi videa prije renderiranja (uloga: naslovnica, udica, kartica, završni)
app/Rendering/VideoRenderer    slajd čeka redak, redak na svom mjestu, glazba se stišava (`narration:`)
app/Jobs/RenderVideoJob        glas nikad ne ruši render; razlog se sprema na video
app/Actions/ChangeVariantVoiceover   prekidač kanala (isti put za panel i MCP)
```

Ručni videi: `hub:render-video --voiceover` i polje `say` po slajdu u scenariju za
`hub:render-storyboard` koriste isti glas (scenarij se ne provjerava protiv iznosa jer nema stavke; provjerava
se samo poveznica i duljina).
