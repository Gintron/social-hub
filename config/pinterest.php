<?php

declare(strict_types=1);

/*
 * Pinterest: weekly pins per brand, handed over as RSS feeds (docs/pinterest.md).
 *
 * Pinterest's API is not an option for a new app: pins made with Trial access are visible only to the account's owner and Standard access
 * (public pins) is granted by review. Business accounts can instead connect an RSS feed to a board ("Auto-publish"); Pinterest fetches it and
 * creates the pins within 24 hours. The hub renders the pin pictures, keeps the feed, and never talks to Pinterest.
 *
 * A pin links to a page that stays up (a shop's recipes, the list of dishes), not to one dish: a dish's offers end on Wednesday, a pin lives for months.
 * The picture shows the dish of the week, the text promises nothing that ends (no prices, no dates).
 *
 * `boards` are the feeds: key => the board's name on Pinterest. `pins` are made once a week, each from the best dish of its own kind:
 *  - `chain`      only dishes of this shop (`raw.recipe.chain`); without it any shop
 *  - `max_minutes` only dishes done within this many minutes
 *  - `path`       the page it links to, under the brand's `site_url`
 *  - `shop`       the shop as the text names it ("BILLA")
 */
return [
    'brands' => [
        'uselisto-at' => [
            'boards' => [
                'angebote' => 'Rezepte mit BILLA-, SPAR- und Hofer-Angeboten',
                'guenstig' => 'Günstige Rezepte aus dem Prospekt',
                'wochenplan' => 'Wochenplan: günstig kochen',
                'schnell' => 'Schnelle Rezepte unter 30 Minuten',
            ],
            'ai_note' => 'Bild: KI-Illustration.',
            'pins' => [
                ['id' => 'billa', 'board' => 'angebote', 'chain' => 'billa', 'path' => '/rezepte/billa', 'shop' => 'BILLA', 'eyebrow' => 'BILLA-Prospekt', 'headline' => 'Günstig kochen mit dem BILLA-Prospekt', 'title' => 'Günstig kochen mit dem BILLA-Prospekt'],
                ['id' => 'spar', 'board' => 'angebote', 'chain' => 'spar', 'path' => '/rezepte/spar', 'shop' => 'SPAR', 'eyebrow' => 'SPAR-Angebote', 'headline' => 'Was kochen mit den SPAR-Angeboten?', 'title' => 'Was kochen mit den SPAR-Angeboten?'],
                ['id' => 'hofer', 'board' => 'angebote', 'chain' => 'hofer', 'path' => '/rezepte/hofer', 'shop' => 'Hofer', 'eyebrow' => 'Hofer-Flugblatt', 'headline' => 'Rezepte aus dem Hofer-Flugblatt', 'title' => 'Rezepte aus dem Hofer-Flugblatt'],
                ['id' => 'penny', 'board' => 'guenstig', 'chain' => 'penny', 'path' => '/rezepte/penny', 'shop' => 'PENNY', 'eyebrow' => 'PENNY-Angebote', 'headline' => 'Günstig kochen mit den PENNY-Angeboten', 'title' => 'Günstig kochen mit den PENNY-Angeboten'],
                ['id' => 'lidl', 'board' => 'guenstig', 'chain' => 'lidl', 'path' => '/rezepte/lidl', 'shop' => 'Lidl', 'eyebrow' => 'Lidl-Prospekt', 'headline' => 'Rezepte aus dem Lidl-Prospekt', 'title' => 'Rezepte aus dem Lidl-Prospekt'],
                ['id' => 'interspar', 'board' => 'wochenplan', 'chain' => 'interspar', 'path' => '/rezepte/interspar', 'shop' => 'INTERSPAR', 'eyebrow' => 'INTERSPAR-Angebote', 'headline' => 'Wochenplan mit den INTERSPAR-Angeboten', 'title' => 'Wochenplan mit den INTERSPAR-Angeboten'],
                ['id' => 'wochenplan', 'board' => 'wochenplan', 'chain' => null, 'path' => '/rezepte', 'shop' => 'BILLA, SPAR, Hofer, Lidl und PENNY', 'eyebrow' => 'Wochenplan günstig', 'headline' => 'Was kochen diese Woche?', 'title' => 'Wochenplan günstig: Was kochen diese Woche?'],
                ['id' => 'schnell', 'board' => 'schnell', 'chain' => null, 'max_minutes' => 25, 'path' => '/rezepte', 'shop' => 'BILLA, SPAR, Hofer, Lidl und PENNY', 'eyebrow' => 'Schnell gekocht', 'headline' => 'Schnelle Rezepte aus den Wochenangeboten', 'title' => 'Schnelle Rezepte aus den Wochenangeboten'],
            ],
        ],
    ],
    // How long a pin stays in the feed. Pinterest creates a pin once; this only keeps the file from growing for ever.
    'feed_days' => 60,
];
