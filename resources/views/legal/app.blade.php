<!doctype html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Social Hub — o aplikaciji</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1.5rem; line-height: 1.6; color: #111827; }
        h1 { margin-bottom: .5rem; }
        h2 { margin-top: 2.5rem; font-size: 1.1rem; }
        a { color: #0d9488; }
    </style>
</head>
<body>
    <h1>Social Hub</h1>
    <p>O aplikaciji, posljednja izmjena: {{ now()->format('d.m.Y.') }}</p>

    <h2>Što je Social Hub</h2>
    <p>
        Social Hub je interni alat za pripremu i objavljivanje sadržaja na društvenim mrežama za brendove
        studentski-poslovi.hr, radim.hr i uselisto.com (Listo). Nije javna usluga: nema registracije ni
        korisničkih računa za posjetitelje. Administracijsko sučelje mogu koristiti samo ovlašteni članovi tima.
    </p>

    <h2>Što alat radi</h2>
    <p>
        Tim priprema objave iz sadržaja brenda. Svaku objavu pregleda i odobri čovjek prije nego što ode na
        mrežu. Objave idu samo na račune koje vlasnik izričito poveže: Facebook stranice, Instagram poslovne
        račune, TikTok račune i YouTube kanale.
    </p>

    <h2>YouTube</h2>
    <p>
        Kad tim odobri kratki uspravni video (YouTube Shorts), alat ga učita na YouTube kanal brenda
        (opseg <code>youtube.upload</code>). Alat čita broj pregleda, lajkova i komentara vlastitih videa
        (opseg <code>youtube.readonly</code>) da bi tim vidio učinak objava. Ne čita komentare, poruke ni podatke
        o pretplatnicima.
    </p>

    <h2>Pravila</h2>
    <p>
        <a href="{{ url('/privatnost') }}">Pravila privatnosti</a> · <a href="{{ url('/uvjeti') }}">Uvjeti korištenja</a>
    </p>

    <h2>English summary</h2>
    <p>
        Social Hub is an internal tool that the owner's brands use to prepare and publish content on social networks.
        A team member approves every post before it is published. The YouTube integration uploads a short vertical
        video (YouTube Shorts) that the team has approved to the brand's own YouTube channel (scope
        <code>youtube.upload</code>), and reads the view, like and comment counts of its own uploads (scope
        <code>youtube.readonly</code>). It does not read comments, messages or subscriber data. Only authorized team
        members can use the administration panel.
    </p>
    <p>
        Privacy policy: <a href="{{ url('/privatnost') }}">{{ url('/privatnost') }}</a><br>
        Terms of use: <a href="{{ url('/uvjeti') }}">{{ url('/uvjeti') }}</a><br>
        Contact: marijan10vk@gmail.com
    </p>
</body>
</html>
