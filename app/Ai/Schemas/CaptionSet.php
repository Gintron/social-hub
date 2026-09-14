<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/**
 * What the drafting agent must return: one caption per network, plus the bits a post needs around
 * them. Hashtags are one space-separated string rather than a list because an untyped array leaves
 * the generated JSON schema without an item type.
 */
final class CaptionSet implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'Tekst za Facebook objavu. Hrvatski, bez markdowna. Smije sadržavati poveznicu iz podataka.')]
    public string $facebook;

    #[Constrained(description: 'Tekst za Instagram objavu. Hrvatski, bez poveznica (na Instagramu nisu klikabilne), najviše 2200 znakova.')]
    public string $instagram;

    #[Constrained(description: 'Tekst za TikTok. Hrvatski, bez poveznica. Prvi red je naslov do 90 znakova: posao i najvažniji podatak (iznos, mjesto). Zatim kratak opis. Bez hashtagova.')]
    public string $tiktok;

    #[Constrained(description: 'Hashtagovi odvojeni razmakom, s ljestvicom, mala slova, najviše 30. Bez dijakritike.')]
    public string $hashtags;

    #[Constrained(description: 'Kratki opis slike za osobe koje ne vide sliku, do 300 znakova.')]
    public string $alt_text;

    #[Constrained(description: 'Ako neki podatak nedostaje ili je sumnjiv, napiši to ovdje. Inače prazno.')]
    public ?string $note = null;

    public static function description(): ?string
    {
        return 'Tekstovi objave za jednu stavku sadržaja.';
    }

    /**
     * @return list<string>
     */
    public function hashtagList(): array
    {
        $tags = preg_split('/[\s,]+/u', mb_trim($this->hashtags)) ?: [];

        $normalized = [];

        foreach ($tags as $tag) {
            $tag = mb_trim($tag);

            if ($tag === '') {
                continue;
            }

            $normalized['#'.mb_strtolower(mb_ltrim($tag, '#'))] = true;
        }

        return array_keys($normalized);
    }
}
