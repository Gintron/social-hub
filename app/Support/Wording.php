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
            'recipe.question' => 'Što kuhati ovaj tjedan?',
            'recipe.hook.on_sale' => ':count :ingredients na akciji',
            'recipe.hook.saving' => 'ušteda :amount',
            'recipe.hook.time' => 'Gotovo za :minutes :unit',
            'recipe.hook.from_leaflet' => 'Sastojci iz letka',
            'recipe.hook.leaflet' => 'Iz letka na tanjur',
            'recipe.card.on_sale' => ':on od :total sastojaka na akciji',
            'recipe.card.on_sale_label' => 'Na akciji',
            'recipe.card.sum_label' => 'Zbroj',
            'recipe.card.saving_label' => 'Ušteda',
            'recipe.card.minutes' => ':minutes min',
            'recipe.card.servings' => ':count :portions',
            'recipe.card.until' => 'Vrijedi do :date',
            'recipe.card.until_short' => 'do :date',
            'recipe.card.saving_label_long' => 'Ušteda prema letku',
            'recipe.card.footer' => 'Recept i popis za kupnju',
            'recipe.list.title' => 'Moj popis',
            'recipe.list.on_sale' => 'na akciji',
            'recipe.list.added' => ':count na popisu',
            'recipe.ai_label' => 'AI ilustracija',
            'recipe.ai_note' => 'Slika jela je AI ilustracija.',
            'recipe.cta.line' => 'Recept i popis su u aplikaciji :brand.',
            'recipe.caption.ask' => 'Hoće li ovo biti ručak ovaj tjedan?',
            'recipe.caption.body' => ':title: :on od :total sastojaka je na akciji :shop, zajedno :sum.',
            'recipe.caption.saving' => 'Ušteda prema letku: :amount.',
            'recipe.caption.sum_note' => 'Zbroj je cijena jednog pakiranja svakog sastojka na akciji.',
            'recipe.comment' => 'Recept i popis za kupnju',
            'recipe.link.comment' => 'Poveznica je u komentaru.',
            'recipe.link.bio' => 'Poveznica je u biografiji.',
            'recipe.link.description' => 'Poveznica je u opisu.',
            'recipe.cover.count' => ':count :recipes iz akcija',
            'recipe.cover.swipe' => 'Prelistaj',
            'voice.recipe.on_sale' => ':on od :total sastojaka je na akciji :shop.',
            'voice.recipe.on_sale_after_count' => 'To je :on od :total sastojaka, sve :shop.',
            'voice.recipe.saving' => 'Ušteda prema letku: :amount.',
            'ingredients.one' => 'sastojak',
            'ingredients.few' => 'sastojka',
            'ingredients.many' => 'sastojaka',
            'portions.one' => 'porcija',
            'portions.few' => 'porcije',
            'portions.many' => 'porcija',
            'minutes.one' => 'minutu',
            'minutes.few' => 'minute',
            'minutes.many' => 'minuta',
            'recipes.one' => 'jelo',
            'recipes.few' => 'jela',
            'recipes.many' => 'jela',
            'digest.recipe' => 'Što kuhati ovaj tjedan: :count :recipes iz akcija',
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
            'recipe.question' => 'Was kochen diese Woche?',
            'recipe.hook.on_sale' => ':count :ingredients im Angebot',
            'recipe.hook.saving' => 'Ersparnis :amount',
            'recipe.hook.time' => 'In :minutes :unit fertig',
            'recipe.hook.from_leaflet' => 'Zutaten aus dem Prospekt',
            'recipe.hook.leaflet' => 'Aus dem Prospekt auf den Teller',
            'recipe.card.on_sale' => ':on von :total Zutaten im Angebot',
            'recipe.card.on_sale_label' => 'Im Angebot',
            'recipe.card.sum_label' => 'Zusammen',
            'recipe.card.saving_label' => 'Ersparnis',
            'recipe.card.minutes' => ':minutes Min.',
            'recipe.card.servings' => ':count :portions',
            'recipe.card.until' => 'Gültig bis :date',
            'recipe.card.until_short' => 'bis :date',
            'recipe.card.saving_label_long' => 'Ersparnis lt. Prospekt',
            'recipe.card.footer' => 'Rezept und Einkaufsliste',
            'recipe.list.title' => 'Meine Liste',
            'recipe.list.on_sale' => 'im Angebot',
            'recipe.list.added' => ':count auf der Liste',
            'recipe.ai_label' => 'KI-Illustration',
            'recipe.ai_note' => 'Das Bild ist eine KI-Illustration.',
            'recipe.cta.line' => 'Rezept und Einkaufsliste findest du in der App :brand.',
            'recipe.caption.ask' => 'Wäre das dein Essen diese Woche?',
            'recipe.caption.body' => ':title: :on von :total Zutaten sind :shop im Angebot, zusammen :sum.',
            'recipe.caption.saving' => 'Ersparnis laut Prospekt: :amount.',
            'recipe.caption.sum_note' => 'Die Summe ist der Preis je einer Packung jeder Zutat im Angebot.',
            'recipe.comment' => 'Rezept und Einkaufsliste',
            'recipe.link.comment' => 'Den Link findest du im Kommentar.',
            'recipe.link.bio' => 'Den Link findest du in der Bio.',
            'recipe.link.description' => 'Den Link findest du in der Beschreibung.',
            'recipe.cover.count' => ':count :recipes im Angebot',
            'recipe.cover.swipe' => 'Weiter',
            'voice.recipe.on_sale' => ':on von :total Zutaten sind :shop im Angebot.',
            'voice.recipe.on_sale_after_count' => 'Das sind :on von :total Zutaten, alle :shop.',
            'voice.recipe.saving' => 'Ersparnis laut Prospekt: :amount.',
            'ingredients.one' => 'Zutat',
            'ingredients.few' => 'Zutaten',
            'ingredients.many' => 'Zutaten',
            'portions.one' => 'Portion',
            'portions.few' => 'Portionen',
            'portions.many' => 'Portionen',
            'minutes.one' => 'Minute',
            'minutes.few' => 'Minuten',
            'minutes.many' => 'Minuten',
            'recipes.one' => 'Rezept',
            'recipes.few' => 'Rezepte',
            'recipes.many' => 'Rezepte',
            'digest.recipe' => 'Was kochen diese Woche: :count :recipes im Angebot',
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
