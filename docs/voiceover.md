# Voice-over (ElevenLabs, naglasci OpenAI)

Videi koje hub sam slaže (Reels i TikTok) mogu imati glas koji izgovara ono što je na slajdovima:
što je ponuda ili posao, koliko košta, koliki je popust, do kada vrijedi i što brend traži od
gledatelja. Uključuje se jednom, na brendu; dalje hub sve radi sam — od pisanja teksta do miksa —
i urednik ne mora ništa dirati. Dva davatelja: **ElevenLabs** izgovara, a **OpenAI** prije toga
označi naglaske u onome što glas treba pročitati (vidi *Naglasci*).

## Uključivanje

1. **Ključevi.** U okolinu poslužitelja (Ploi → Environment) dodaj `ELEVENLABS_API_KEY` i
   `OPENAI_API_KEY` (isti OpenAI ključ piše i tekstove objava, `hub:agent-draft`). ElevenLabs ključ smije
   biti ograničen: treba mu pravo za text-to-speech, a za popis glasova i pregled potrošnje u panelu i
   pravo čitanja glasova i korisnika (bez toga hub radi, samo ID glasa zalijepiš ručno).
   Deploy skripta već radi `queue:restart`, pa novi ključ stiže do workera.
2. **Glas.** Brendovi → *Voice-over (ElevenLabs)* → odaberi glas (★ = ElevenLabs ga navodi kao
   provjerenog za hrvatski) i uključi *Videi ovog brenda dobivaju voice-over*.
3. **Preslušaj.** Gumb *Preslušaj glas* izgovori rečenicu s postavkama iz obrasca (i onima koje još
   nisu spremljene) i prikaže kako je hub napisao brojeve. Isto s naredbom:

   ```bash
   ./vendor/bin/sail artisan hub:voiceover-test --brand=uselisto
   ./vendor/bin/sail artisan hub:voiceover-test "Kruh 500 g za 1,49 €" --voice=<ID>
   ./vendor/bin/sail artisan hub:voiceover-test --brand=uselisto --compare
   ./vendor/bin/sail artisan hub:voiceover-test --list-voices
   ```
   `--compare` izgovori istu rečenicu bez oznaka naglaska i sa svakim načinom njihova zapisa, da se čuje
   koji glas najbolje razumije (vidi *Naglasci*).
4. **Provjera.** `hub:doctor` javlja ima li ključeva (OpenAI pita za modele koje će koristiti, ElevenLabs za
   plan), koliko je plana ostalo i koji brend traži glas koji ne može imati.

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
sam ne zna izvesti (ime lanca je podatak stranice, ne hubovo znanje). VELIKA SLOVA od četiri slova naviše (`LOVRAN`, `SPAR`) hub pretvara u
ime; kratice (`TV`, `PDV`, `USB`) čita slovima.

### Izmjena teksta

*Renderiraj ponovno* na video kanalu pokazuje što glas govori, redak po slajdu. Izmijeni samo ono što
glas čita krivo; prazan redak ostavlja slajd uz glazbu. Ako ništa ne izmijeniš, tekst se ponovno piše
iz stavke (pa se promijenjena cijena na izvoru ne zamrzne). Ispod videa stoji što je glas rekao.

## Naglasci

Glas koji čita hrvatski tu i tamo krivo naglasi riječ: rijetku, ime, posuđenicu, riječ koja se piše
jednako kao neka druga. To nije pravilo koje bi hub mogao zapisati; to zna model koji poznaje jezik.
Zato **svaki redak prije izgovora prolazi kroz OpenAI** (`Accenter`), koji u riječima koje bi glas mogao
naglasiti krivo označi naglašeni samoglasnik. Prolaze svi retci koji će se izgovoriti: napisani iz stavke,
izmijenjeni u dijalogu, završna rečenica brenda, probni zapis u panelu.

- **Model smije samo dodati oznaku.** Dobije redak već raspisan riječima i popis riječi koje smije označiti
  (najmanje dva samoglasnika, samo slova hrvatske abecede), svaku s rednim brojem. Za riječ koju označi vrati
  istu riječ s jednim naglaskom. Kod svaku oznaku provjerava (`Stress::position`): mora biti ista riječ, ista
  slova i ista veličina slova, jedan naglasak, na samoglasniku. Što nije, odbaci se i riječ se izgovara kakva
  je bila. Model ne može promijeniti što se govori: ni iznos, ni ime.
- **Bolje ništa nego krivo.** Uputa traži da se riječ ne označi ako model nije siguran u naglasak. Označava
  se rijetko: riječi koje se pišu jednako a naglašavaju različito (prema smislu rečenice), imena, nazivi
  trgovina i marki, posuđenice, rjeđi oblici s naglaskom koji nije na prvom slogu.
- **Označava se jednom.** Odgovor se sprema (`voiceover_accents`; ključ: redak, model i verzija upute), pa
  isti redak u svakom videu dobiva iste oznake i već plaćeni isječak se ponovno nalazi. Model koji razmišlja
  ne odgovori dvaput isto, a redak s drugačijim oznakama je za ElevenLabs drugi tekst. Cijeli video ide
  jednim zahtjevom, i to samo s recima koje još nitko nije označio.
- **Načini zapisa** (Brendovi → Voice-over → *Naglasci*; zadano `VOICEOVER_ACCENTS`):

  | Način | Što glas dobije |
  |---|---|
  | `acute` | akut na naglašenom samoglasniku, „kúća“ (kako se naglasak piše u rječniku) |
  | `caps` | veliko slovo na naglašenom samoglasniku, „kUća“ (trik koji ElevenLabs navodi za modele bez phoneme tagova, `trapezIi`) |
  | `off` | tekst kakav jest; OpenAI se ne pita i njegov ključ nije potreban |

  Oznaka se sprema kao mjesto (koja riječ, koji samoglasnik), ne kao znak, pa promjena načina ne pita model
  ponovno.
- **Što glas s oznakom napravi, čuje se; ne čita.** ElevenLabs za hrvatski ne navodi kako čita oznake
  naglaska: `eleven_v4` prima IPA između kosih crta (`"/ˈkuːtʃa/"`, u dokumentaciji samo engleski
  primjeri). Zato prije uključivanja pokreni
  `hub:voiceover-test --brand=uselisto --compare "…rečenica s imenom ili riječi koju glas griješi…"`:
  izgovori se bez oznaka, s akutom i s velikim slovom (jedan upit OpenAI-ju, tri isječka), pa odaberi način
  koji zvuči najbolje, a ako nijedan nije bolji od `off`, ostavi `off`. Oznake u tekstu vidi i tko zna
  hrvatski: naredba ih ispisuje, a ispod svakog videa piše *Glas čita: …* za svaki redak.
- **Model i razmišljanje.** Zadano isti kao za tekstove (`OPENAI_MODEL`, `OPENAI_EFFORT`); naglasci mogu imati
  svoje: `OPENAI_ACCENT_MODEL`, `OPENAI_ACCENT_EFFORT`. Poznavanje naglaska je prije znanje nego račun, pa
  bi ovdje jači model trebao pomoći više od dužeg razmišljanja (pretpostavka: provjeri na svojim recima). Zahtjev je kratak (nekoliko stotina tokena), pa je cijena reda
  veličine centa po videu, i to samo za nove retke.
- **Bez naglasaka nema glasa.** Redak koji se nije mogao provjeriti ne izgovara se (ni ne plaća na ElevenLabsu):
  video izlazi bez glasa i razlog piše na kanalu (`accents_*`, tablica dolje). Ako to nije željeno, na brendu
  postavi *Naglasci: Isključeno* (ili `VOICEOVER_ACCENTS=off` za sve) i glas se izgovara bez pomoći OpenAI-ja.

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
koliko je bilo znakova (`characters`) i koliko je ElevenLabs naplatio (`cost`). Ključ je tekst **s oznakama
naglasaka**, pa se izmjena načina zapisa naglasaka plaća kao novi tekst (samo za retke koji se ponovno
izgovaraju).

**`characters` i `cost` nisu isto.** `characters` je duljina izgovorenog teksta (`mb_strlen`, hub je računa
sam). `cost` je zaglavlje odgovora `character-cost`: dokumentacija ga opisuje kao „cijenu generiranja u
znakovima“, ali izmjereno je drugo, ono što je zahtjev naplaćen u kreditima računa, i ovisi o modelu. Hub govori
samo modelom v4 (vidi *Kvaliteta hrvatskog*); na njemu (2026-10-03, plan `payg`) redci od 10, 43, 87 i 219
znakova dali su 1, 3, 5 i 13 kredita, dakle oko 0,06 kredita po znaku. Isti redak od 43 znaka na drugim modelima
koštao je 14 (multilingual v2), 9 (v3) i 7 (flash v2.5), pa je pri prelasku na noviji model prvo što treba
napraviti `hub:voiceover-test` i pogledati stupac *Kredita*. Zbroj zaglavlja slaže se približno s dnevnom
potrošnjom u kreditima (`GET /v1/usage/character-stats?metric=credits`). `cost` je `null` kad API zaglavlje nije
poslao; duljinu teksta nikad ne glumi. Za koliko je koji brend potrošio zbroji `cost`, ne `characters`.

Na `payg` planu `character_count` iz `/v1/user/subscription` (to je ono što `hub:doctor` i panel zovu
„koliko je plana ostalo“) nije se pomaknuo ni nakon osam poziva u nekoliko minuta (izmjereno 2026-10-03), pa
nemoj po njemu zaključivati da ništa nije potrošeno; dnevna potrošnja je u `character-stats`.

Procjena: oko 250 znakova po videu, 10 videa dnevno ≈ 75 000 znakova mjesečno, a to je na v4 oko 4 500 kredita. Gornja granica po videu je `ELEVENLABS_MAX_CHARACTERS_PER_VIDEO` (800; oko minute govora, a Facebook
Reel smije 90 s); mjeri se duljinom skripte (`ScriptGuard`), ne zaglavljem.
`hub:prune-voiceovers` (tjedno, nedjeljom) briše zvučne datoteke starije od 60 dana; zapisi ostaju, a
ista rečenica, ako zatreba, izgovori se ponovno pod istim zapisom.

## Kad glas ne uspije

Glas je dodatak videu, nikad njegov uvjet. Nema ključa, plan je potrošen, glas više ne postoji,
ElevenLabs ili OpenAI (naglasci) nisu dostupni ili je tekst odbijen — video se renderira **bez glasa**
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
| `accents_not_configured` | Naglasci su uključeni, a nema `OPENAI_API_KEY` | Postavi ključ ili na brendu isključi naglaske |
| `accents_invalid_key` / `accents_forbidden` | OpenAI ključ ne vrijedi ili projekt ne smije koristiti model | Novi ključ; dopusti model u OpenAI projektu |
| `accents_quota_exceeded` | Na OpenAI računu nema kredita | Dopuni Billing |
| `accents_model_not_found` | Ime modela ne postoji | `OPENAI_ACCENT_MODEL` / `OPENAI_MODEL`, provjeri s `hub:doctor` |
| `accents_unavailable`, `accents_rate_limited` | OpenAI zapinje | Hub sam pokuša četiri puta; ništa |
| `accents_incomplete`, `accents_invalid_output`, `accents_refused`, `accents_rejected` | Model nije vratio upotrebljiv odgovor | Renderiraj ponovno; ako se ponavlja, drugi model ili naglasci isključeni |

Kad se uzrok ukloni, *Renderiraj ponovno* dodaje glas (video bez glasa se ne koristi ponovno za kanal koji
ga traži).

## Ograničenja i što provjeriti

- **Pozivi prema ElevenLabsu i OpenAI-ju nisu isprobani živim ključem u razvoju.** ElevenLabs zahtjev je
  napisan po njihovoj dokumentaciji (`POST /v1/text-to-speech/{voice_id}`, zaglavlje `xi-api-key`,
  `voice_settings`, `mp3_44100_128`) i pokriven testovima s lažnim odgovorom koji je pravi MP3. OpenAI zahtjev
  je Responses API sa strogom JSON shemom (`text.format` `json_schema`, `strict`) i isto je pokriven lažnim
  odgovorima. Prvo što treba napraviti s pravim ključevima je `hub:doctor` i `hub:voiceover-test --compare`.
- **Nije provjereno da glas oznake naglaska čita kako treba.** To je pretpostavka iza cijele te značajke
  (vidi *Naglasci*), i jedino što je može potvrditi je uho. Ako ni akut ni veliko slovo ne pomažu,
  `off` vraća ono što je bilo prije, a treći način zapisa (IPA između kosih crta, koju v4 prima) je moguće
  nadopuniti u `Stress::render` bez ponovnog pitanja modela.
- **Točnost naglaska je točnost modela.** Kod provjerava da je riječ ista, ne da je naglasak dobar; to znanje
  je modelovo. Zato je uputa oprezna (ne označuj ako nisi siguran) i zato se označeno vidi ispod videa.
- **Jedini model je `eleven_v4`** (Marijan, 03. 10. 2026.), a kad ElevenLabs izda noviji, njega. Model je
  `ELEVENLABS_MODEL` i popis `models` u `config/elevenlabs.php`; u kodu nema grane po imenu modela, a brend čija je
  spremljena postavka izvan popisa (stari `eleven_multilingual_v2`) dobiva zadani. Novi model: dodaj ga u popis,
  preslušaj `hub:voiceover-test --compare` i pogledaj stupac *Kredita*. Kvaliteta hrvatskog ovisi o glasu i
  modelu; v4 navodi hrvatski, ali ga treba preslušati. Brojeve izgovara hub (`SpokenCroatian`), ne model.
- **Oznaka umjetnog sadržaja.** Glas je sintetiziran. TikTok i Meta imaju pravila o označavanju
  AI-generiranog sadržaja i hub ih ne postavlja sam (nije provjereno postoji li u TikTok Business
  API-ju parametar za tu oznaku). Pročitaj njihova pravila prije nego uključiš glas na javnim računima.
- **Licenca glasa.** Komercijalna upotreba traži plaćeni ElevenLabs plan; neki glasovi iz biblioteke imaju
  vlastite uvjete.
- **ffmpeg 4.4 ili noviji** (`amix` s opcijom `normalize`); `hub:doctor` to provjerava.

## Video „izašao je novi katalog“

Ima vlastiti scenarij od četiri rečenice (`App\Catalog\CatalogCopy`) umjesto teksta po slajdu, ali isti put
do glasa: `Narrator::narrateItems` → provjera → `SpokenCroatian` → (naglasci/izgovor) → `Synthesizer` s ključem u
`voiceovers`. Rečenice 2–4 su iste za sve lance, pa se plaćaju jednom; prva nosi lanac („Konzumov katalog“).
Glas ne ruši render, video bez glasa je isti video (vidi `catalog-video.md`).

## Gdje je u kodu

```
app/Voiceover/SpokenCroatian   brojevi, iznosi, postoci, jedinice, datumi → riječi
app/Voiceover/ScriptBuilder    piše tekst po slajdu iz stavke (Highlights, price, facts)
app/Voiceover/ScriptGuard      iznosi i poveznice moraju biti iz stavke (CaptionValidator)
app/Voiceover/Accenter         redak → OpenAI → provjerene oznake naglaska, spremljene u `voiceover_accents`
app/Voiceover/Stress           mjesto naglaska kao podatak: koje riječi, provjera oznake, zapis (akut, veliko slovo)
app/Ai/OpenAiClient            Responses API sa strogom JSON shemom; zajednički klijent za tekstove i naglaske
app/Voiceover/ElevenLabsClient HTTP klijent, mapiranje grešaka, popis glasova, potrošnja
app/Voiceover/Synthesizer      jedan redak → jedan isječak, keš u `voiceovers`, trajanje i glasnoća
app/Voiceover/Narrator         tekst → provjera → izgovor → naglasci → isječci po slajdu
app/Rendering/ScenePlanner     slajdovi videa prije renderiranja (uloga: naslovnica, udica, kartica, završni)
app/Rendering/VideoRenderer    slajd čeka redak, redak na svom mjestu, glazba se stišava (`narration:`)
app/Jobs/RenderVideoJob        glas nikad ne ruši render; razlog se sprema na video
app/Actions/ChangeVariantVoiceover   prekidač kanala (isti put za panel i MCP)
```

Ručni videi: `hub:render-video --voiceover` i polje `say` po slajdu u scenariju za
`hub:render-storyboard` koriste isti glas (scenarij se ne provjerava protiv iznosa jer nema stavke; provjerava
se samo poveznica i duljina).
