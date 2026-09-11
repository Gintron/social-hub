<!doctype html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pravila privatnosti — Social Hub</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1.5rem; line-height: 1.6; color: #111827; }
        h1 { margin-bottom: .5rem; }
        h2 { margin-top: 2.5rem; font-size: 1.1rem; }
        a { color: #0d9488; }
    </style>
</head>
<body>
    <h1>Pravila privatnosti</h1>
    <p>Social Hub, posljednja izmjena: {{ now()->format('d.m.Y.') }}</p>

    <p>
        Social Hub je interni alat za objavljivanje sadržaja na društvenim mrežama u ime brendova
        studentski-poslovi.hr, radim.hr i uselisto.com (Listo). Ova stranica opisuje koje podatke
        alat obrađuje i zašto.
    </p>

    <h2>Koje podatke obrađujemo</h2>
    <p>
        Isključivo podatke poslovnih društvenih računa (Facebook stranica, Instagram poslovni
        račun, TikTok račun) koje njihov vlasnik izričito poveže s alatom: naziv i identifikator
        računa te pristupni token izdan kroz službeni OAuth dijalog dotične mreže. Alat ne
        prikuplja niti obrađuje podatke krajnjih korisnika, pratitelja ili posjetitelja tih
        profila.
    </p>

    <h2>Kako čuvamo podatke</h2>
    <p>
        Pristupni tokeni spremaju se šifrirano u bazi podataka i koriste se isključivo za
        objavljivanje sadržaja koji tim koji upravlja brendom unaprijed pripremi i odobri.
    </p>

    <h2>Dijeljenje s trećim stranama</h2>
    <p>
        Podaci se ne prodaju niti dijele s trećim stranama. Jedina komunikacija ide prema samoj
        društvenoj mreži (Meta, TikTok) kroz njihov službeni API, u mjeri nužnoj za objavu sadržaja.
    </p>

    <h2>Uklanjanje pristupa i brisanje podataka</h2>
    <p>
        Vlasnik povezanog računa može u svakom trenutku ukloniti pristup u postavkama dotične
        mreže (npr. "Ukloni aplikaciju"). Nakon toga se pripadajući pristupni token trajno briše.
        Za Facebook/Instagram to je dodatno automatizirano callbackom koji mreža sama pozove;
        zahtjev za ručno brisanje može se poslati i izravno na kontakt niže.
    </p>

    <h2>Kontakt</h2>
    <p>Pitanja o privatnosti: <a href="mailto:marijan10vk@gmail.com">marijan10vk@gmail.com</a>.</p>

    <p><a href="{{ url('/uvjeti') }}">Uvjeti korištenja</a></p>
</body>
</html>
