# Listo: video kampanje usmjerene na prvu listu

Početni pregled od 26. 9. 2026. Pripremljeni su lokalni videi i promjene huba. Naknadna
iteracija automatskih serija opisana je niže.
Video primjeri, scenariji i tekstovi su u `artifacts/listo-growth/`, pregled u `index.html`.

## Što je provjereno

- Produkcija ima sedam dnevnih serija „Top 3 akcija”, po trgovinama, u 18:30. Uz njih postoji
  automatski izbor pojedinačnih ponuda. Izvor ponuda filtrira popuste od 35 %.
- Odabir se oslanja na prioritet/popust: u pregledanim objavama često dominiraju uređaji i posuđe;
  jedan pregled Bipe sadrži dva proizvoda istog naziva (različita pakiranja). To je slabija veza s
  redovitom kupnjom i slaganjem liste. To je procjena sadržaja, ne dokazan uzrok slabog dosega.
- U spremljenom uzorku postoji šest objavljenih TikTok varijanti, četiri s mjerenjem: 93, 257, 273
  i 812 pregleda (medijan 265). Starost se razlikuje, najnovija još nije završila distribuciju.
  Uzorak nije dovoljan za tvrdnju da TikTok ograničava račun na 250 pregleda.
- Korisnik je dostavio i TikTok analitiku za video „Gdje je maslac ovaj tjedan najjeftiniji?”:
  trajanje 8,8 s, 273 pregleda, prosječno gledanje 2,4 s (oko 27 % trajanja), 2,17 % gledanja
  do kraja te nula oznaka sviđanja, komentara, dijeljenja, spremanja i novih pratitelja. To je
  jasan signal da ovaj video slabo zadržava pažnju i ne potiče reakciju, ali uzorak jednog videa
  ne dokazuje razlog. Prikazani graf broji preglede po danima; bez krivulje zadržavanja nije
  poznato u kojoj sekundi gledatelji odlaze.
- Instagram: 27 objavljenih varijanti s mjerenjem, medijan 0; uzorak uključuje i carousele.
  Provjereni izvorni Meta odgovori doista sadrže nule, nisu zamijenjene vrijednosti koje nedostaju.
- Javni Instagram profil pokazuje 0 pratitelja. To ne isključuje preporučivanje ne-pratiteljima.
  Korisnik je 26. 9. 2026. dostavio snimku zaslona „Ograničenja vašeg dosega”: Instagram navodi
  „Nemate ograničenja dosega korisničkog računa”, a „Ljudi koji vas ne prate” ima zelenu potvrdu.
  Račun i sadržaj mogu se preporučivati u Istraži, Reelsima i sažetku. To potvrđuje podobnost za
  preporuke, ali ne jamči distribuciju niti objašnjava slab doseg. Objave jesu javno vidljive.
- `uselisto.com/instagram` vodi na naslovnicu s `utm_source=instagram`, a gumbi za trgovine
  prenose izvor: App Store `ct=instagram-profil-dolazak`, Google Play Install Referrer s izvorom
  `instagram`. Put od profila do preuzimanja postoji i gumbi su na vrhu aktualne stranice.
- Hub ne sprema prosječno vrijeme gledanja, dovršenost ni instalacije/aktivaciju. Za te podatke
  trebaju platformni Insights i analitika aplikacije. Nedostajuća metrika nije nula.

## Promjena sadržaja

Prva poruka treba biti situacija kupca, zatim vidljiva korist, a završetak jedna radnja.
Za prvi test pripremljeni su:

| Kampanja | Početak | Dokaz | Radnja |
|---|---|---|---|
| Bez prepisivanja | Još prepisuješ cijene iz letka? | Odabir proizvoda u stvarnom sučelju i primjer liste | Preuzmi Listo i dodaj prvi proizvod |
| Prije blagajne | Koliko će te koštati ova kupnja? | Prikaz procijenjenog zbroja po trgovini | Složi svoju prvu listu |
| Pošalji listu | „Što ono trebam kupiti?” | Prikaz podijeljene liste | Napravi listu i podijeli je |

Zbog analitike videa od 8,8 s pripremljene su i dvije verzije „Bez prepisivanja” od 9 s.
Prve 2 s razlikuju se samo po naslovu: **A** postavlja pitanje „Još prepisuješ cijene iz letka?”,
**B** odmah kaže korist „Iz letka na listu. Bez prepisivanja.” Idućih 3,5 s u obje verzije
prikazuju isti primjer liste i zbroja, a posljednjih 3,5 s isti poziv „Preuzmi Listo” i prvi korak.
Podloga, ostali kadrovi i tekst objave isti su. To je test početne poruke, ne dokaz da će kraća
verzija imati veći doseg. Samo organsko objavljivanje nije nasumični A/B test: dan i promjene u
distribuciji mogu utjecati na rezultat. Usporediti svaku verziju nakon istog broja sati i pokušaj
ponoviti poboljšani početak na idućem videu.

Videi, njihove naslovne slike, tekstovi objava i izvorni scenariji nalaze se u
[`artifacts/listo-growth/`](../artifacts/listo-growth/index.html). Za objavu se koriste gotovi
`video.mp4` i pripadni `*-caption.txt`; JSON služi za doradu i ponovno renderiranje.

Prikazi su službene App Store snimke, nisu snimka obavljenih dodira. Cijene su jasno označene kao
primjer, ne kao aktualna ponuda. Podijeljena lista je za čitanje; ne obećavamo zajedničko uređivanje.
Zbroj je procjena, odvojen po trgovini. Probni rok je 20 dana bez kartice, nakon njega godišnja
pretplata za slaganje i uređivanje listi, što piše u pripremljenim tekstovima.

Za novi miks predlažem zamijeniti većinu dnevnih pregleda trgovina ovim temama. Zadržati jedan
koristan tjedni pregled i testirati usporedbe svakodnevnih proizvoda. Uspoređivati cijenu po kg/l
uz naziv i pakiranje; različite marke ili BIO/standard nisu „isti proizvod”. Ne obećavati točan
iznos uštede za cijelu košaricu iz usporedbe pojedinačnih ponuda.

## Test tijekom dva tjedna

Ovo je prijedlog za pregled, nije aktiviran raspored. Ostaje postojeći termin 18:30 Europe/Zagreb
kako promjena vremena ne bi dodatno otežala tumačenje rezultata.

| Dan | Video |
|---|---|
| pon 28. 9. | Bez prepisivanja, 9 s — A: pitanje |
| sri 30. 9. | Jedna aktualna usporedba svakodnevnog proizvoda, uz provjeru podataka |
| pet 2. 10. | Prije blagajne |
| ned 4. 10. | Kratak pregled triju korisnih ponuda kao kontrola |
| pon 5. 10. | Bez prepisivanja, 9 s — B: korist odmah |
| sri 7. 10. | Nova aktualna usporedba |
| pet 9. 10. | Pošalji listu |
| ned 11. 10. | Tjedni pregled triju korisnih ponuda |

Usporedbe trebaju svježe cijene na dan objave; ne koristiti sadašnje ponude u budućem videu.
Za ispitivanje početne poruke mijenjati samo tekst prvog kadra, uz isti nastavak, glazbu i
završetak. Dva ponedjeljka u isto vrijeme smanjuju jednu razliku između A i B, ali ne uklanjaju
ostale utjecaje.
Nije potrebno gasiti sve postojeće serije prije prvog testa. Nakon izbora plana serije se mogu
smanjiti u panelu, bez uključivanja novih auto-publish ovlasti.

Prikupiti rezultate nakon 24 i 72 sata za oba kanala odvojeno:
Prazna polja u `artifacts/listo-growth/rezultati-predlozak.csv` znače da mjera nije prikupljena,
ne da je rezultat nula.

- Pregledi, dosegnuti računi, prosječno vrijeme gledanja i dovršenost; trajanje svakog videa uz njih.
- Dijeljenja i spremanja na 1000 pregleda, posjeti profilu i klikovi na poveznicu, gdje su dostupni.
- Dolasci na stranicu, klikovi prema trgovinama, instalacije i prvo dodavanje proizvoda u aplikaciji.
  Krajnja mjera je nova aktivirana osoba, ne samo preuzimanje.

Poveznica u profilu identificira kanal, ali sama ne dokazuje koja je objava proizvela instalaciju.
Za pojedinačne kampanje trebaju zasebne mjerene poveznice/oznake i podrška atribuciji u aplikaciji.
Ne pripisivati sve instalacije toga dana posljednjem videu. Za iOS provjeriti dostupnost i pragove
App Store kampanjskih izvještaja; za Android da aplikacija stvarno čita Install Referrer.

Kod ovako malog dosega rezultate čitati kao smjer, ne kao statistički dokaz. Ne proglasiti pobjednika
na temelju jednog videa. Ako novi početak bolje zadržava, a nema klikova, provjeriti poziv na radnju.
Ako klikovi stižu bez prve stavke, provjeriti trgovinu aplikacija i prvo korištenje.

## Nakon pushanja koda

Ploi je 27. 9. 2026. automatski preuzeo commit `affd0e4`; produkcijski `hub:doctor` prošao je.
Promjena nema migraciju baze. Produkcijskom Listu postavljeni su `voice.video_style=direct`,
`voice.cta=Preuzmi Listo`, `voice.pitch=Letak → tvoja lista.`, `voice.cta_note=20 dana bez kartice`
i `voice.activation=Dodaj prvi proizvod s letka.` Ostale postavke brenda su sačuvane. Budući
automatski renderirani videi Lista zato koriste izravne rezove, kraći uvod i poziv na prvi korak.

Gotovi MP4-ovi iz galerije predviđeni su za ljudski pregled i ručni upload kao TikTok i Instagram
Reel. Scenariji ne stvaraju nacrte, ne uključuju nova `auto_publish_rules` i ne mijenjaju sedam
postojećih dnevnih serija. Za dvotjedni test čovjek odabire i objavljuje termine; usporedba nakon
24 i 72 sata ide u predložak rezultata.

## Primjena u hubu

U Brendovi → Glas brenda dodane su postavke:

- **Ritam videa: Izravni rezovi, kraći uvod** (`voice.video_style=direct`): uvodni hook ili naslovnica
  do 2,5 s, ponude ostaju po postavci serije (zadano 3 s), završni kadar najmanje 3,5 s.
- **Prvi korak nakon preuzimanja** (`voice.activation`): zamjenjuje konkurentski poziv na spremanje/
  dijeljenje na završnom kadru i u Instagram/TikTok tekstovima. Primjer: „Dodaj prvi proizvod s letka.”
- Predloženi CTA: „Preuzmi Listo”; kratki pitch: „Letak → tvoja lista.”; napomena „20 dana bez kartice”.

Zadano ponašanje drugih brendova ostaje isto. Kodeci su i dalje H.264/yuv420p + AAC, faststart.
Izravni rezovi su stvarni rezovi bez preklapanja teksta. Snimke predložaka služe i carouselima;
`kinds/feature-portrait` i `kinds/feature-story` koriste isti dizajn za generičku demonstraciju.

Za ponovljiv pregled kampanje koristi se JSON scenarij s provjerenim tekstovima, lokalnim slikama
i trajanjem svakog kadra. Relativne putanje slika i glazbe računaju se od JSON datoteke:

```bash
./vendor/bin/sail artisan hub:render-storyboard artifacts/listo-growth/01-bez-prepisivanja.json \
  --brand=uselisto --output=artifacts/listo-growth/01-bez-prepisivanja
```

Naredba izrađuje medije i lokalni MP4 s preglednim slikama. Ne radi nacrt, ne odobrava, ne zakazuje
i ne objavljuje. Novi videi su zato spremni za ljudski pregled i odabir. Isječak prvih 18 sekundi
postojeće Listo podloge je uz scenarije u `artifacts/listo-growth/source/background.mp3`, tako da se mogu ponovno
renderirati bez prethodnog lokalnog primjera. Ondje je izvor bio označen kao Pixabay Music;
licenca nije ponovno neovisno provjerena.

## Izvori i granice

- [Službena stranica Lista](https://uselisto.com/) i
  [službeni App Store zapis](https://apps.apple.com/hr/app/id6808256097): funkcionalnosti i prikazi.
- [TikTok: Creative best practices](https://ads.tiktok.com/resources/help/article/creative-best-practices):
  rani razlog za gledanje, jasan CTA, čitljiv tekst i testiranje različitih pristupa. Smjernice su
  za oglase; ovdje služe kao polazište za organski test, ne kao jamstvo dosega.
- [Instagram Best Practices](https://about.fb.com/news/2024/10/best-practices-education-hub-creators-instagram/):
  procjena stvaranja, angažmana i dosega kroz profesionalnu nadzornu ploču.

Viralan doseg nije moguće jamčiti. Ako korisnik može snimiti svoj glas i stvarnu radnju u aplikaciji,
sljedeći test treba usporediti tu snimku s ovim automatiziranim prikazima, s istom porukom.

## Automatske serije: iteracija 27. 9. 2026.

Ovo je zasebna promjena na postojeće automatske videe. Ne uključuje pripremljene ručne kampanje
iznad i ne dodaje novu ovlast za automatsko objavljivanje.

- Listov Social Feed v1 sada računa `priority` prema procijenjenoj korisnosti za redovitu kupnju:
  hrana i kućanske potrepštine dobivaju prednost, uređaji/posuđe i ponude bez cijene padaju niže.
  Popust i dalje odlučuje među sličnim proizvodima. Procjena se temelji na naslovu i cijeni;
  nema pouzdane kategorije ni dovoljno podataka o navikama korisnika za osobnu preporuku.
- Pregled 2.434 aktualne ponude pri pragu 20 % pokazao je da postoje i jeftiniji artikli ispod
  sadašnjeg praga. Produkcijski prag ostao je **35 %** jer je i unutar njega dovoljno korisnih
  ponuda, a njegovo snižavanje bi nepotrebno proširilo backlog automatskih objava. Primjeri
  stvarnog izbora nakon novog bodovanja: Konzum — kruh, krumpir, tuna;
  Plodine — pasirana rajčica, tjestenina, riža; Kaufland — špinat, maslinovo ulje, šećer.
  To je provjera poretka, ne dokaz boljeg dosega ili konverzije.
- Digest preskače isti proizvod kad je naslov identičan, drukčije poredan ili gotovo jednak
  naslovu drugog pakiranja. Izbor pregledava do 100 kandidata da pritom ne ostane bez ponuda.
- Izravni 9:16 naslovni kadar pokazuje tri proizvoda s cijenama i popustima, trgovinu i Listovu
  korist u sigurnoj zoni. Slajd proizvoda uklanja veliki drugi znak popusta i centrira cijenu.
  Ostali omjeri i brendovi zadržavaju dosadašnji predložak.
- Svih sedam postojećih serija ostaje na 18:30. Naslov je kratak, s brojem stvarno izabranih
  akcija; slajd ponude traje 2,5 s, pa pregled tri akcije traje približno 13,5 s.

Produkcija je preuzela `listo` commit `a994f89` i `social-hub` commit `d30aca9`.
Jednokratni inline sync pri nepromijenjenom pragu od 35 % donio je 411 novih i osvježio 257
postojećih stavki. Nije stvorio nijedan nacrt; postojeći limit pojedinačnih objava ostaje jedna
dnevno po kanalu, a sedam digest serija zadržalo je svoje termine. `hub:doctor` je prošao.

Pratiti 24 i 72 sata nakon svakog videa, odvojeno po mreži: dovršenost, prosječno gledanje,
spremanja i dijeljenja, profil/poveznicu te nove instalacije i prve dodane proizvode. Usporedba
sa starim videima nije kontrolirani eksperiment; mijenjaju se i ponude i vizual. Ako gledanje
poraste bez prvog dodanog proizvoda, sljedeća iteracija treba popraviti put do aplikacije.

## Košarica redovite kupnje: sljedeća iteracija

Listo trenutačno ima približno deset korisnika. Njihovo ponašanje nije reprezentativan dokaz što
hrvatski kupci često kupuju, pa algoritam ne uči iz njihovih lista ni kupnji. Kao **orijentir**
služi [DZS-ova Anketa o potrošnji kućanstava u 2022.](https://podaci.dzs.hr/2023/hr/84175):
hrana i bezalkoholna pića činili su 27 % izdataka, a u izdacima za hranu veliki su udjeli mesa,
žitarica, mliječnih proizvoda i jaja te povrća. Iznos potrošnje nije učestalost kupnje, a podaci
su stari nekoliko godina; izbor konkretnih proizvoda zato je urednička hipoteza za test, ne
empirijski rang proizvoda ni obećanje boljeg dosega.

Listov Social Feed v1 sada iz naziva proizvoda dodjeljuje opću oznaku košarice: meso/riba,
pekarski proizvodi, mliječno/jaja, voće/povrće, osnovne namirnice, kućanstvo ili osobna njega.
Prioritet unutar njih daje prednost kruhu, piletini, mlijeku/jajima/jogurtu, krumpiru/povrću,
brašnu/tjestenini/riži i kućnim potrepštinama. Veći popust razlikuje slične ponude, ali ne može
sam potisnuti redovitu kupnju u korist aparata, slatkiša ili specijaliteta. Ponuda bez cijene
pada niže. To je samo metapodatak izvora; hub i dalje čita isti generički Social Feed v1 adapter.
Digest za tri akcije prvo bira različite oznake i marke, zatim dopušta drugu iz iste grupe ako
nema dovoljno izbora. Postojeći prag od 35 %, termin i odobrenja nisu promijenjeni.

Simulacija na presjeku aktualnih ponuda pri postojećem pragu (27. 9. 2026.; ne uračunava već
iskorištene stavke ni raspodjelu marke po poljima feeda) daje primjere: Konzum — kruh, krumpir, pileći file;
Plodine — smrznuta mrkva, toaletni papir, tjestenina; Kaufland — jogurt, pecivo, deterdžent;
Bipa — kapsule za posuđe, müsli, gel za tuširanje. Stvarni redoslijed ovisi o novim letcima,
isteku ponuda i pravilima ponavljanja u hubu. Pregledati buduće objave i njihove metrike prije
tvrdnje da je promjena poboljšala instalacije ili korištenje aplikacije.

## Reel usporedbe: uključivanje (29. 9. 2026.)

Listov feed već vraća usporedbe (`GET /v1/social-feed?kind=comparison`, `kind: comparison` u Social Feed v1):
najjeftiniji proizvod po kg/l u letku svakog lanca, s udicom, rangom i pozivom na akciju. Hub ih crta
istim setom slajdova kao i sve ostalo, pa je **Reel usporedbe zaseban izvor s vlastitim pravilima**, ne kod:

1. **Brendovi → Listo → Termini objave → „Najviše automatskih objava dnevno“ = 3.** Limit je za cijeli brend
   (pojedinačne stavke svih izvora i pregledi zajedno), po lokalnom danu objave. Prije toga treba proći deploy
   s migracijom `2026_09_29_000004`.
2. **Izvori → novi izvor** `listo-usporedbe`: brend Listo, vrsta Social Feed v1, isti URL feeda i isti ključ kao
   postojeći izvor `listo`; u „Napredno → Postavke“ ključ `query.kind` = `comparison`. „Testiraj vezu“ mora
   vratiti stavke vrste Usporedba.
3. **Automatska objava** na tom izvoru, po jedno pravilo za Facebook stranicu i Instagram: format **Reel**,
   „Najviše dnevno“ = 1, uključeno. TikTok je uvijek video: pravilo s isporukom „izravno“ i „Najviše dnevno“ = 1.
   Uključi i „Objavi i ono što je dnevni limit zadržao“: identitet usporedbe je tema + ISO tjedan, pa ona koja
   stigne kad je dan pun inače nikad ne izađe.
4. Zbroj po danu: jutarnje pravilo izvora `listo` (1 po kanalu) + večernji pregled (1) + usporedba (1) = 3.
   Ako je u panelu više jutarnjih objava ili više serija istog dana, limit ne dopušta četvrtu; redoslijed
   popunjavanja je redoslijed stvaranja (jutarnje stavke, usporedba, pa pregledi koji se grade sat prije
   termina), pa u tom slučaju **pregledi gube mjesto prvi**. Prije uključivanja provjeri u panelu koliko
   serija je stvarno na istim danima.

### Rok valjanosti ponude

Ponuda smije u sadržaj samo ako vrijedi još **3 dana nakon objave** (`config/hub.php` → `min_days_valid`, za
vrste `deal` i `comparison`). Rok se mjeri od termina u kojem će objava izaći: usporedba čija prva ponuda
istječe u srijedu ne ide u ponedjeljak u 18:30, jer ponuda s `expires_at` u srijedu 23:59 UTC ne doseže četvrtak
u 18:30. Usporedba istječe s najranijom ponudom u retcima (`expires_at` = najraniji `valid_to`), pa jedna
ponuda pred istekom zadržava cijelu temu do sljedećeg tjedna.
Pravilo vrijedi za pojedinačne objave, preglede (svaku stavku posebno) i kandidate koje vide agent i MCP
alati (od trenutka upita, jer vrijeme objave tada određuje čovjek). Ručno zakazan nacrt ne blokira: čovjek
odlučuje. Već zakazane objave se ne provjeravaju iznova.
