<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use InvalidArgumentException;

/**
 * The hub's own words on a brand's channels, in the brand's language (`brands.language`).
 *
 * The item's words are the source's: a title, a fact, a call to action arrive written in the language of the site. What
 * the hub adds around them — "u prvom komentaru", the line under a roundup, the sentences of the new-catalog video, the
 * voice-over's glue — is written here, once per language, so a German brand never posts a Croatian "Link u biu".
 *
 * Croatian is every brand that existed before the choice, and its words are exactly what the code said before they
 * moved here. A key missing in one language is a test failure (WordingTest), never a silent fall back to Croatian.
 */
final class Wording
{
    public const LANGUAGES = ['hr', 'de'];

    public const DEFAULT = 'hr';

    private const WORDS = [
        'hr' => [
            'caption.first_comment' => 'u prvom komentaru',
            'caption.all_offers' => 'Sve ponude',
            'caption.link_in_bio' => 'Link u biu',
            'caption.share_friend' => 'Pošalji prijatelju kojem ovo treba',
            'caption.save_list' => 'Spremi popis i pošalji ga osobi s kojom kupuješ',
            'footer.job' => 'Detalji o poslu',
            'footer.deal' => 'Detalji o akciji',
            'footer.event' => 'Detalji o događaju',
            'footer.comparison' => 'Usporedi sve ponude',
            'footer.other' => 'Više',
            'digest.deal' => 'Top :count akcija ovog tjedna',
            'digest.job' => 'Top :count oglasa ovog tjedna',
            'digest.event' => ':count događaja koje ne propuštaš',
            'digest.comparison' => ':count usporedbi cijena',
            'digest.other' => 'Izdvojeno: :count novosti',
            'digest.badge' => 'do −:percent %',
            'offers.one' => 'ponuda',
            'offers.few' => 'ponude',
            'offers.many' => 'ponuda',
            'template.cheapest' => 'NAJJEFTINIJE',
            'template.deal_eyebrow' => 'AKCIJA U TRGOVINI',
            'template.share' => 'Spremi objavu i pošalji je prijatelju',
            'catalog.news' => 'Hej, izašao je novi :phrase.',
            'catalog.how' => 'Prelistaj ga i dodaj proizvod na svoju listu dodirom na letak.',
            'catalog.cta' => 'Preuzmi :brand',
            'catalog.link.comment' => 'Poveznica do aplikacije je u komentaru.',
            'catalog.link.bio' => 'Poveznica do aplikacije je u biografiji.',
            'catalog.link.description' => 'Poveznica do aplikacije je u opisu.',
            'catalog.title' => 'Izašao je novi',
            'catalog.title_chain' => ':phrase',
            'catalog.headline' => 'Izašao je novi :phrase',
            'catalog.validity' => 'Vrijedi od :from do :to',
            'catalog.tap' => 'Dodirni proizvod.',
            'catalog.tapped' => 'Dodan je na listu.',
            'catalog.list' => 'Tvoja lista',
            'catalog.list_ready' => 'je spremna.',
            'app.lists' => 'Liste',
            'app.mine' => 'Moje liste',
            'app.shared' => 'Podijeljeno',
            'app.share' => 'Podijeli',
            'app.to_buy' => 'Za kupiti',
            'app.add' => 'Dopiši',
            'app.total' => 'Ukupno',
            'app.catalogs' => 'Katalozi',
            'app.search' => 'Traži',
            'app.settings' => 'Postavke',
            'app.added' => 'Dodano u listu',
            'app.product' => 'Naziv proizvoda',
            'voice.discounts_up_to' => 'Popusti do :percent %.',
            'voice.in_shop' => 'u trgovini :shop',
            'voice.for_price' => 'za :figure',
            'voice.discount' => 'Popust :percent %.',
            'voice.at_shop' => 'U trgovini',
            'voice.cheapest' => 'Najjeftinije:',
            'voice.cheapest_word' => 'najjeftinije',
            'voice.then' => 'Zatim',
            'voice.valid_until' => 'Vrijedi do :date.',
            'voice.and' => 'i',
            'voice.dangling' => 's|sa|u|i|a|o|na|za|od|do|uz|iz|po|te|ili|bez|kao',
            'ai.language' => 'Odgovaraš isključivo na hrvatskom jeziku.',
        ],
        'de' => [
            'caption.first_comment' => 'im ersten Kommentar',
            'caption.all_offers' => 'Alle Angebote',
            'caption.link_in_bio' => 'Link in der Bio',
            'caption.share_friend' => 'Schick es jemandem, der es brauchen kann',
            'caption.save_list' => 'Speichere die Liste und schick sie der Person, mit der du einkaufst',
            'footer.job' => 'Details zum Job',
            'footer.deal' => 'Details zum Angebot',
            'footer.event' => 'Details zur Veranstaltung',
            'footer.comparison' => 'Alle Angebote vergleichen',
            'footer.other' => 'Mehr',
            'digest.deal' => 'Die Top :count Angebote dieser Woche',
            'digest.job' => 'Die Top :count Jobs dieser Woche',
            'digest.event' => ':count Events, die du nicht verpassen solltest',
            'digest.comparison' => ':count Preisvergleiche',
            'digest.other' => 'Ausgewählt: :count Neuigkeiten',
            'digest.badge' => 'bis −:percent %',
            'offers.one' => 'Angebot',
            'offers.few' => 'Angebote',
            'offers.many' => 'Angebote',
            'template.cheapest' => 'AM GÜNSTIGSTEN',
            'template.deal_eyebrow' => 'ANGEBOT IM HANDEL',
            'template.share' => 'Speichere den Beitrag und schick ihn weiter',
            'catalog.news' => 'Hey, der neue Prospekt von :chain ist da.',
            'catalog.how' => 'Blättere ihn durch und tipp auf ein Produkt, um es auf deine Liste zu setzen.',
            'catalog.cta' => 'Hol dir :brand',
            'catalog.link.comment' => 'Den Link zur App findest du im Kommentar.',
            'catalog.link.bio' => 'Den Link zur App findest du in der Bio.',
            'catalog.link.description' => 'Den Link zur App findest du in der Beschreibung.',
            'catalog.title' => 'Neuer Prospekt',
            'catalog.title_chain' => 'von :chain',
            'catalog.headline' => 'Neuer Prospekt von :chain',
            'catalog.validity' => 'Gültig von :from bis :to',
            'catalog.tap' => 'Tipp auf ein Produkt.',
            'catalog.tapped' => 'Schon ist es auf der Liste.',
            'catalog.list' => 'Deine Liste',
            'catalog.list_ready' => 'ist fertig.',
            'app.lists' => 'Listen',
            'app.mine' => 'Meine Listen',
            'app.shared' => 'Geteilt',
            'app.share' => 'Teilen',
            'app.to_buy' => 'Zu kaufen',
            'app.add' => 'Ergänzen',
            'app.total' => 'Gesamt',
            'app.catalogs' => 'Prospekte',
            'app.search' => 'Suchen',
            'app.settings' => 'Einstellungen',
            'app.added' => 'Zur Liste hinzugefügt',
            'app.product' => 'Produktname',
            'voice.discounts_up_to' => 'Rabatte bis zu :percent %.',
            'voice.in_shop' => 'bei :shop',
            'voice.for_price' => 'um :figure',
            'voice.discount' => 'Minus :percent %.',
            'voice.at_shop' => 'Bei',
            'voice.cheapest' => 'Am günstigsten:',
            'voice.cheapest_word' => 'günstigsten',
            'voice.then' => 'Dann',
            'voice.valid_until' => 'Gültig bis zum :date.',
            'voice.and' => 'und',
            'voice.dangling' => 'mit|und|oder|für|von|vom|zum|zur|im|in|an|am|auf|aus|bei|ohne|der|die|das|den|dem|des|ein|eine|einen',
            'ai.language' => 'Odgovaraš isključivo na njemačkom jeziku, kako se piše u Austriji (Erdäpfel, Faschiertes, Jänner).',
        ],
    ];

    /**
     * A stored language as one the hub speaks: an empty or unknown value is Croatian.
     */
    public static function language(?string $language): string
    {
        $language = mb_strtolower(mb_trim((string) $language));

        return in_array($language, self::LANGUAGES, true) ? $language : self::DEFAULT;
    }

    /**
     * The words for `$key`, with `:name` placeholders filled from `$replace`.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function get(Brand|string|null $language, string $key, array $replace = []): string
    {
        $language = $language instanceof Brand ? $language->languageCode() : self::language($language);
        $words = self::WORDS[$language][$key] ?? throw new InvalidArgumentException("No words for '{$key}' in '{$language}'.");

        foreach ($replace as $name => $value) {
            $words = str_replace(':'.$name, (string) $value, $words);
        }

        return $words;
    }

    /**
     * The noun for `$count` things (`offers` → `offers.one|few|many`): Croatian has three forms (1, 21 / 2–4, 22–24 /
     * the rest), German two (1 / the rest).
     */
    public static function plural(Brand|string|null $language, int $count, string $noun): string
    {
        $language = $language instanceof Brand ? $language->languageCode() : self::language($language);

        if ($language === 'hr') {
            $mod10 = $count % 10;
            $mod100 = $count % 100;
            $form = match (true) {
                $mod10 === 1 && $mod100 !== 11 => 'one',
                $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'few',
                default => 'many',
            };
        } else {
            $form = $count === 1 ? 'one' : 'many';
        }

        return self::get($language, "{$noun}.{$form}");
    }

    /**
     * Every key of one language, for the test that holds the languages to the same set.
     *
     * @return list<string>
     */
    public static function keys(string $language): array
    {
        return array_keys(self::WORDS[$language] ?? []);
    }
}
