<?php

declare(strict_types=1);

/*
 * Image templates rendered with Browsershot. Keys are stable identifiers stored on media_assets.
 * Templates are per content *kind*, themed by the brand (colors, logo); brand-specific overrides
 * are added as new keys with a 'brands' whitelist.
 */
return [
    'kinds/job-square' => ['view' => 'templates.kinds.job', 'width' => 1080, 'height' => 1080, 'kinds' => ['job'], 'label' => 'Oglas — kvadrat'],
    'kinds/job-portrait' => ['view' => 'templates.kinds.job', 'width' => 1080, 'height' => 1350, 'kinds' => ['job'], 'label' => 'Oglas — portret'],
    'kinds/deal-square' => ['view' => 'templates.kinds.deal', 'width' => 1080, 'height' => 1080, 'kinds' => ['deal'], 'label' => 'Akcija — kvadrat'],
    'kinds/deal-portrait' => ['view' => 'templates.kinds.deal', 'width' => 1080, 'height' => 1350, 'kinds' => ['deal'], 'label' => 'Akcija — portret'],
    'kinds/generic-square' => ['view' => 'templates.kinds.generic', 'width' => 1080, 'height' => 1080, 'kinds' => ['article', 'event', 'generic'], 'label' => 'Općenito — kvadrat'],
    'kinds/generic-portrait' => ['view' => 'templates.kinds.generic', 'width' => 1080, 'height' => 1350, 'kinds' => ['article', 'event', 'generic'], 'label' => 'Općenito — portret'],
    'kinds/comparison-square' => ['view' => 'templates.kinds.comparison', 'width' => 1080, 'height' => 1080, 'kinds' => ['comparison'], 'label' => 'Usporedba — kvadrat'],
    'kinds/comparison-portrait' => ['view' => 'templates.kinds.comparison', 'width' => 1080, 'height' => 1350, 'kinds' => ['comparison'], 'label' => 'Usporedba — portret'],

    // Vertikalni format 9:16 za Reels i TikTok; isti pogledi, druga visina.
    'kinds/job-story' => ['view' => 'templates.kinds.job', 'width' => 1080, 'height' => 1920, 'kinds' => ['job'], 'label' => 'Oglas — story 9:16'],
    'kinds/deal-story' => ['view' => 'templates.kinds.deal', 'width' => 1080, 'height' => 1920, 'kinds' => ['deal'], 'label' => 'Akcija — story 9:16'],
    'kinds/generic-story' => ['view' => 'templates.kinds.generic', 'width' => 1080, 'height' => 1920, 'kinds' => ['article', 'event', 'generic'], 'label' => 'Općenito — story 9:16'],
    'kinds/comparison-story' => ['view' => 'templates.kinds.comparison', 'width' => 1080, 'height' => 1920, 'kinds' => ['comparison'], 'label' => 'Usporedba — story 9:16'],

    // Digest: naslovnica i slajdovi za carousel od više stavki odjednom.
    'kinds/digest-cover' => ['view' => 'templates.kinds.digest-cover', 'width' => 1080, 'height' => 1080, 'kinds' => ['job', 'deal', 'article', 'event', 'generic', 'comparison'], 'label' => 'Digest — naslovnica'],
    'kinds/digest-cover-portrait' => ['view' => 'templates.kinds.digest-cover', 'width' => 1080, 'height' => 1350, 'kinds' => ['job', 'deal', 'article', 'event', 'generic', 'comparison'], 'label' => 'Digest — naslovnica (portret)'],
    'kinds/digest-cover-story' => ['view' => 'templates.kinds.digest-cover', 'width' => 1080, 'height' => 1920, 'kinds' => ['job', 'deal', 'article', 'event', 'generic', 'comparison'], 'label' => 'Digest — naslovnica 9:16'],

    // Set slajdova jedne stavke (config/template_sets.php): udica prije kartice, poziv na akciju poslije.
    // Namjerno iza vrsta: defaultFor() uzima prvi ključ koji odgovara, a to mora ostati kartica stavke.
    'kinds/hook-square' => ['view' => 'templates.kinds.hook', 'width' => 1080, 'height' => 1080, 'kinds' => ['job', 'deal', 'article', 'event', 'generic'], 'label' => 'Udica — kvadrat'],
    'kinds/hook-portrait' => ['view' => 'templates.kinds.hook', 'width' => 1080, 'height' => 1350, 'kinds' => ['job', 'deal', 'article', 'event', 'generic'], 'label' => 'Udica — portret'],
    'kinds/hook-story' => ['view' => 'templates.kinds.hook', 'width' => 1080, 'height' => 1920, 'kinds' => ['job', 'deal', 'article', 'event', 'generic'], 'label' => 'Udica — story 9:16'],
    'kinds/comparison-hook-square' => ['view' => 'templates.kinds.comparison-hook', 'width' => 1080, 'height' => 1080, 'kinds' => ['comparison'], 'label' => 'Usporedba, udica — kvadrat'],
    'kinds/comparison-hook-portrait' => ['view' => 'templates.kinds.comparison-hook', 'width' => 1080, 'height' => 1350, 'kinds' => ['comparison'], 'label' => 'Usporedba, udica — portret'],
    'kinds/comparison-hook-story' => ['view' => 'templates.kinds.comparison-hook', 'width' => 1080, 'height' => 1920, 'kinds' => ['comparison'], 'label' => 'Usporedba, udica — story 9:16'],
    'kinds/cta-square' => ['view' => 'templates.kinds.cta', 'width' => 1080, 'height' => 1080, 'kinds' => ['job', 'deal', 'article', 'event', 'generic', 'comparison'], 'label' => 'Poziv na akciju — kvadrat'],
    'kinds/cta-portrait' => ['view' => 'templates.kinds.cta', 'width' => 1080, 'height' => 1350, 'kinds' => ['job', 'deal', 'article', 'event', 'generic', 'comparison'], 'label' => 'Poziv na akciju — portret'],
    'kinds/cta-story' => ['view' => 'templates.kinds.cta', 'width' => 1080, 'height' => 1920, 'kinds' => ['job', 'deal', 'article', 'event', 'generic', 'comparison'], 'label' => 'Poziv na akciju — story 9:16'],
];
