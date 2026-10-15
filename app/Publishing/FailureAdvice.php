<?php

declare(strict_types=1);

namespace App\Publishing;

/**
 * What a person does about a post that did not go out, said in one sentence per reason.
 *
 * The message on the variant says what happened ("Poveznica stavke … više ne odgovara"); this says
 * what to do next. Only codes whose cause is known from the code that sets them have advice: a
 * platform's own error is shown as it came, and a guess would send someone the wrong way.
 */
final class FailureAdvice
{
    /**
     * @var array<string, string>
     */
    private const KNOWN = [
        'content_expired' => 'Stavka je istekla, pa objava više nema smisla. Napravi novu objavu iz aktualne stavke.',
        'dead_link' => 'Stranica stavke više ne postoji, vjerojatno je uklonjena na izvoru. Odaberi drugu stavku.',
        'link_check_failed' => 'Stranica stavke je odbila provjeru poveznice. Otvori je i provjeri radi li, pa objavi ponovno.',
        'stale_content' => 'Stavka se promijenila otkad je objava pripremljena, a stara se objava ne osvježava. Napravi novu objavu iz stavke.',
        'restricted_goods' => 'Alkohol se ne objavljuje na ovom kanalu. Ako je u tekstu, ukloni ga; ako je u stavci, isključi ovaj kanal ili odaberi drugu stavku.',
        'media_not_ready' => 'Slika ili video ne odgovara formatu kanala. Klikni „Renderiraj ponovno“ pod pregledom.',
        'token_invalid' => 'Pristup računu je istekao ili je opozvan. Poveži račun ponovno u Društvenim računima.',
        'permission_denied' => 'Račun nema potrebnu dozvolu. Poveži ga ponovno i potvrdi sve tražene dozvole.',
        'title_invalid' => 'Naslov (prvi red teksta) sadrži znak < ili >, a YouTube ih ne prima. Ukloni ih iz teksta pa objavi ponovno.',
        'description_invalid' => 'Opis (tekst ispod prvog reda) sadrži znak < ili >, a YouTube ih ne prima. Ukloni ih iz teksta pa objavi ponovno.',
        'invalid_parameter' => 'Platforma je odbila podatke objave, obično format ili duljinu. Provjeri tekst i medij pa objavi ponovno.',
        'rate_limited' => 'Platforma je privremeno ograničila objavljivanje. Hub pokušava ponovno sam; ako ostane, pokušaj kasnije.',
    ];

    private const UNREACHABLE = 'Platforma trenutno ne odgovara. Hub pokušava ponovno sam; ako ostane, pokušaj kasnije.';

    public static function for(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        if (isset(self::KNOWN[$code])) {
            return self::KNOWN[$code];
        }

        // Each platform names its own ("graph_unreachable", "tiktok_transient"); they all mean the same.
        if ($code === 'transient' || str_ends_with($code, '_unreachable') || str_ends_with($code, '_transient')) {
            return self::UNREACHABLE;
        }

        return null;
    }
}
