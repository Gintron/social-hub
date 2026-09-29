# Voice-over (ElevenLabs)

Videi koje hub sam slaže (Reels i TikTok) mogu imati glas koji izgovara ono što je na slajdovima:
što je ponuda ili posao, koliko košta, koliki je popust, do kada vrijedi i što brend traži od
gledatelja. Uključuje se jednom, na brendu; dalje hub sve radi sam — od pisanja teksta do miksa —
i urednik ne mora ništa dirati.

## Uključivanje

1. **Ključ.** U okolinu poslužitelja (Ploi → Environment) dodaj `ELEVENLABS_API_KEY`. Ključ smije
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
   ./vendor/bin/sail artisan hub:voiceover-test --list-voices
   ```
4. **Provjera.** `hub:doctor` javlja ima li ključa, koliko je plana ostalo i koji brend traži glas koji
   ne može imati.

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

## Zvuk i vrijeme

- **Slajd čeka riječi.** Slajd ostaje dok se njegov redak ne izgovori (0,2 s prije, 0,4 s poslije, uz
  prijelaze) i nikad kraće nego bez glasa. Video je zato nešto duži: tipično 12–16 s umjesto 9 s.
- **Brzina** je zadano 1,05 (postavka brenda, 0,7–1,2): sekunda ušteđena na svakom slajdu je sekunda
  zadržane pažnje. Redak dulji od 130 znakova gubi zadnje rečenice; naslov se reže na 60 znakova.
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
koliko znakova.

Procjena: oko 250 znakova po videu, 10 videa dnevno ≈ 75 000 znakova mjesečno. Gornja granica po videu
je `ELEVENLABS_MAX_CHARACTERS_PER_VIDEO` (800; oko minute govora, a Facebook Reel smije 90 s).
`hub:prune-voiceovers` (tjedno, nedjeljom) briše zvučne datoteke starije od 60 dana; zapisi ostaju, a
ista rečenica, ako zatreba, izgovori se ponovno pod istim zapisom.

## Kad glas ne uspije

Glas je dodatak videu, nikad njegov uvjet. Nema ključa, plan je potrošen, glas više ne postoji,
ElevenLabs je nedostupan ili je tekst odbijen — video se renderira **bez glasa** (uz glazbu, ako je
ima) i izlazi na vrijeme. Razlog se sprema uz video (`params.voiceover`) i piše na kanalu u pregledu
(*Video je bez voice-overa: …*); admin dobiva **jedan** e-mail po uzroku na 12 sati.

| Šifra | Znači | Što napraviti |
|---|---|---|
| `not_configured` | Nema ključa ili glasa | `ELEVENLABS_API_KEY`, odabir glasa |
| `invalid_key` / `missing_permissions` | Ključ ne vrijedi ili nema pravo | Novi ključ, pravo *Text to Speech* |
| `quota_exceeded` | Plan je potrošen | Pričekaj obnovu ili nadogradi plan |
| `voice_not_found` | Glas je obrisan | Odaberi glas ponovno |
| `unavailable`, `rate_limited` | ElevenLabs zapinje | Hub sam pokuša četiri puta (do oko 20 s čekanja); ništa |
| `script_rejected` | Redak ne prolazi provjeru | Ispravi redak u dijalogu |

Kad se uzrok ukloni, *Renderiraj ponovno* dodaje glas (video bez glasa se ne koristi ponovno za kanal koji
ga traži).

## Ograničenja i što provjeriti

- **Poziv prema ElevenLabsu nije isproban živim ključem u razvoju.** Zahtjev je napisan po njihovoj
  dokumentaciji (`POST /v1/text-to-speech/{voice_id}`, zaglavlje `xi-api-key`, `voice_settings`, `mp3_44100_128`)
  i pokriven testovima s lažnim odgovorom koji je pravi MP3. Prvo što treba napraviti s pravim ključem
  je `hub:voiceover-test`.
- **Kvaliteta hrvatskog ovisi o glasu i modelu.** Zadano je `eleven_multilingual_v2` (hrvatski
  naveden, bolji s brojevima). `eleven_v4` i `eleven_v3` navode hrvatski, ali ih treba preslušati prije
  uključivanja (v3 ima samo tri razine stabilnosti pa ih hub zaokružuje); Flash v2.5 hrvatski ne navodi.
- **Oznaka umjetnog sadržaja.** Glas je sintetiziran. TikTok i Meta imaju pravila o označavanju
  AI-generiranog sadržaja i hub ih ne postavlja sam (nije provjereno postoji li u TikTok Business
  API-ju parametar za tu oznaku). Pročitaj njihova pravila prije nego uključiš glas na javnim računima.
- **Licenca glasa.** Komercijalna upotreba traži plaćeni ElevenLabs plan; neki glasovi iz biblioteke imaju
  vlastite uvjete.
- **ffmpeg 4.4 ili noviji** (`amix` s opcijom `normalize`); `hub:doctor` to provjerava.

## Gdje je u kodu

```
app/Voiceover/SpokenCroatian   brojevi, iznosi, postoci, jedinice, datumi → riječi
app/Voiceover/ScriptBuilder    piše tekst po slajdu iz stavke (Highlights, price, facts)
app/Voiceover/ScriptGuard      iznosi i poveznice moraju biti iz stavke (CaptionValidator)
app/Voiceover/ElevenLabsClient HTTP klijent, mapiranje grešaka, popis glasova, potrošnja
app/Voiceover/Synthesizer      jedan redak → jedan isječak, keš u `voiceovers`, trajanje i glasnoća
app/Voiceover/Narrator         tekst → provjera → izgovor → isječci po slajdu
app/Rendering/ScenePlanner     slajdovi videa prije renderiranja (uloga: naslovnica, udica, kartica, završni)
app/Rendering/VideoRenderer    slajd čeka redak, redak na svom mjestu, glazba se stišava (`narration:`)
app/Jobs/RenderVideoJob        glas nikad ne ruši render; razlog se sprema na video
app/Actions/ChangeVariantVoiceover   prekidač kanala (isti put za panel i MCP)
```

Ručni videi: `hub:render-video --voiceover` i polje `say` po slajdu u scenariju za
`hub:render-storyboard` koriste isti glas (scenarij se ne provjerava protiv iznosa jer nema stavke; provjerava
se samo poveznica i duljina).
