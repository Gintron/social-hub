<!doctype html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zahtjev za brisanje podataka</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1.5rem; line-height: 1.6; color: #111827; }
        code { background: #f3f4f6; padding: .15rem .4rem; border-radius: .25rem; }
        .status { border-left: 4px solid #0d9488; padding-left: 1rem; margin: 2rem 0; }
    </style>
</head>
<body>
    <h1>Zahtjev za brisanje podataka</h1>
    <div class="status">
        <p>Potvrdni kod: <code>{{ $code }}</code></p>
        <p>
            @if ($accounts > 0)
                Obrađeno. Uklonjeni su pristupni tokeni za {{ $accounts }} povezan(ih) računa, a računi su isključeni.
            @else
                Nema zapisa uz ovaj kod — ili je zahtjev već obrađen, ili uz taj Facebook račun nije bio povezan nijedan kanal.
            @endif
        </p>
    </div>
    <p>
        Ova aplikacija sprema samo pristupne tokene i identifikatore Facebook stranica i Instagram računa
        koje je vlasnik izričito povezao radi objavljivanja vlastitog sadržaja. Ne sprema profile,
        objave ni podatke drugih korisnika.
    </p>
</body>
</html>
