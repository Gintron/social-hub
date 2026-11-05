<?php

declare(strict_types=1);

/*
 * Goods a channel does not take. Data, not code: a new spelling, brand or channel is one line here.
 *
 * `platforms` are Platform values, every channel the hub publishes to: alcohol is refused on all of them.
 * RestrictedGoodsCheck refuses any other name, so a misspelt channel cannot switch its block off without a sound;
 * a new channel goes here before it is connected. `alcohol.terms` are words, matched whole and case-insensitively,
 * so "vino" does not match "vinograd"; a trailing `*` takes any ending ("rakij*" is rakija,
 * rakije, rakijom). `exceptions` and `volume` are regular expressions without delimiters. The same word list is kept
 * in the Listo feed (server/api/catalogDemo.ts, ALCOHOL_WORDS): change both together.
 */
return [
    'platforms' => ['fb_page', 'fb_group', 'ig_business', 'tiktok', 'youtube'],

    'alcohol' => [
        'terms' => [
            // Drinks. Adjectives that also name other things stay out on purpose: "vinski ocat" (vinegar),
            // "žestoko sniženje" (a deep discount).
            'vino', 'vina', 'vinu', 'vinom', 'vinima',
            'pivo', 'piva', 'pivu', 'pivom', 'pivima',
            'pjenušav*', 'pjenušac*', 'šampanjac*', 'prosecco',
            'rakij*', 'šljivovic*', 'travaric*', 'pelinkovac*', 'komovic*', 'medovin*',
            'votk*', 'vodka', 'viski*', 'whisk*', 'rum', 'gin', 'džin', 'dzin', 'konjak*', 'liker*',
            'tekila', 'tequila', 'grappa', 'vermut*', 'bermet*', 'sangrij*', 'aperol', 'campari',
            'alkohol*',
            'žestoka pića', 'žestokih pića', 'žestokim pićima',
            'wine', 'beer',
            // Grape varieties and brands whose name says the drink.
            'graševin*', 'malvazij*', 'plavac*', 'merlot', 'cabernet*', 'chardonnay', 'sauvignon*', 'rizling*',
            'traminac*', 'pinot*',
            'ožujsko', 'karlovačko', 'jägermeister', 'heineken', 'corona', 'tuborg', 'stella artois', 'carlsberg',
            'jim beam', 'jack daniel*', 'johnnie walker', 'jameson', 'smirnoff', 'absolut', 'bacardi',
            'captain morgan', 'chivas', 'ballantine*',
            // German (Listo Österreich). Compounds are listed by their start because a term only takes endings, not
            // beginnings: "Rotwein" is not caught by "wein". "Weintrauben" (grapes) and "Weinessig" (vinegar) stay out
            // on purpose, so plain "wein" has no `*`.
            'wein', 'weine', 'weins',
            'rotwein*', 'weißwein*', 'weisswein*', 'roséwein*', 'rosewein*', 'schaumwein*', 'qualitätswein*',
            'glühwein*', 'gluehwein*', 'apfelwein*', 'weinbrand*', 'jagertee', 'sekt', 'sekte', 'sektes', 'henkell', 'söhnlein', 'schlumberger', 'mionetto', 'champagner*', 'spumante',
            'cremant', 'crémant', 'cider', 'cidre',
            'bier*', 'weißbier*', 'weissbier*', 'weizenbier*', 'märzenbier*', 'kellerbier*', 'craftbier*', 'bockbier*',
            'dunkelbier*', 'pils', 'pilsner*', 'zwickl*',
            'schnaps*', 'spirituosen', 'likör*', 'obstler*', 'brandy', 'cognac', 'wodka*', 'whisky*', 'whiskey*',
            'amaretto', 'baileys', 'eierlikör*', 'averna', 'fernet*', 'ouzo', 'sambuca', 'kahlua', 'kahlúa', 'malibu', 'lillet',
            'stiegl', 'gösser', 'zipfer', 'puntigamer', 'ottakringer', 'schwechater', 'murauer', 'hirter', 'villacher',
            'paulaner', 'erdinger', 'weihenstephaner', 'franziskaner', 'budweiser', 'bitburger', 'krombacher', 'warsteiner',
            'beck\'s', 'becks', 'desperados', 'guinness', 'peroni', 'hochprozentig*',
        ],

        // Removed before the words are read. "bezalkoholno pivo" is a drink with no alcohol: the phrase goes, its noun
        // with it (alone, "bezalkoholno" would still match "alkohol"). A zero percentage is no drink, but a digit right
        // before it ("40,0 % vol") is part of the volume, not a zero.
        'exceptions' => [
            '(?:bezalkohol|nealkohol)\p{L}*(?:\s+(?:pivo|piva|pivu|pivom|pivima|vino|vina|vinu|vinom|vinima|pjenu\p{L}*)(?!\p{L}))?',
            '(?<![\p{N}.,])0[,.]0\s*%(?:\s*vol(?!\p{L}))?',
            '(?<![\p{N}.,])0[,.]0\s*vol\.?\s*-?\s*%',
            // German: "alkoholfreies Bier", "Weißbier alkoholfrei", "ohne Alkohol" are no drinks of the kind; a "Bierschinken" is a ham.
            'alkoholfrei\p{L}*(?:\s+(?:\p{L}*(?:bier|wein|sekt|cider|cidre)|schaumwein|punsch)\p{L}*)?',
            '(?:\p{L}*bier|\p{L}*wein|\p{L}*sekt)\s+alkoholfrei\p{L}*',
            'ohne\s+alkohol',
            'bier(?:schinken|wurst|senf|rettich|brot)\p{L}*',
        ],

        // Alcohol by volume: "11,5 % vol", "12% vol.", and the German "12 Vol.-%".
        'volume' => '(?:\d+(?:[,.]\d+)?\s*%\s*vol(?!\p{L})|\d+(?:[,.]\d+)?\s*vol\.?\s*-?\s*%)',
    ],
];
