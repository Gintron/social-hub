<!doctype html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Uvjeti korištenja — Social Hub</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1.5rem; line-height: 1.6; color: #111827; }
        h1 { margin-bottom: .5rem; }
        h2 { margin-top: 2.5rem; font-size: 1.1rem; }
        a { color: #0d9488; }
    </style>
</head>
<body>
    <h1>Uvjeti korištenja</h1>
    <p>Social Hub, posljednja izmjena: {{ now()->format('d.m.Y.') }}</p>

    <p>
        Social Hub je interni alat za planiranje i objavljivanje sadržaja na društvenim mrežama
        (Facebook, Instagram, TikTok) u ime brendova studentski-poslovi.hr, radim.hr i uselisto.com
        (Listo). Nije samostalan proizvod niti javna usluga — nema registracije, korisničkih računa ni
        funkcija namijenjenih krajnjim potrošačima.
    </p>

    <h2>Tko smije koristiti alat</h2>
    <p>
        Pristup administratorskom sučelju ograničen je na članove tima koji ovlašteno upravljaju
        navedenim brendovima. Nijedan dio alata nije javno dostupan.
    </p>

    <h2>Povezivanje društvenih računa</h2>
    <p>
        Vlasnik poslovnog Facebook, Instagram ili TikTok računa svjesno povezuje taj račun s alatom
        (OAuth prijavom kroz službeni dijalog dotične mreže) kako bi alat u njegovo ime objavljivao
        sadržaj koji tim unaprijed pripremi, pregleda i odobri. Svaka objava prolazi kroz ljudsko
        odobrenje prije nego što ode na mrežu; alat sam ne odlučuje što objaviti.
    </p>

    <h2>Prestanak pristupa</h2>
    <p>
        Vlasnik povezanog računa može u svakom trenutku ukinuti pristup izravno u postavkama
        dotične društvene mreže (npr. "Ukloni aplikaciju" na Facebooku ili TikToku). Nakon toga alat
        više ne može objavljivati u ime tog računa.
    </p>

    <h2>Kontakt</h2>
    <p>Pitanja o ovim uvjetima: <a href="mailto:marijan10vk@gmail.com">marijan10vk@gmail.com</a>.</p>

    <p><a href="{{ url('/privatnost') }}">Pravila privatnosti</a></p>
</body>
</html>
