<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Ai\CaptionWriterException;

/**
 * What the drafting agent must return: one caption per network, plus the bits a post needs around
 * them. Hashtags are one space-separated string rather than a list because that is what a model
 * writes most reliably and what `hashtagList()` needs.
 *
 * `schema()` is what OpenAI holds the model to while it writes; `fromArray()` is the way back, and
 * refuses anything that is not that shape.
 */
final class CaptionSet
{
    /** The fields and what each is for — the descriptions are read by the model as part of the schema. */
    private const FIELDS = [
        'facebook' => 'Tekst za Facebook objavu. Hrvatski, bez markdowna. Smije sadržavati poveznicu iz podataka.',
        'instagram' => 'Tekst za Instagram objavu. Hrvatski, bez poveznica (na Instagramu nisu klikabilne), najviše 2200 znakova.',
        'tiktok' => 'Tekst za TikTok. Hrvatski, bez poveznica. Prvi red je naslov do 90 znakova: posao i najvažniji podatak (iznos, mjesto). Zatim kratak opis. Bez hashtagova.',
        'hashtags' => 'Hashtagovi odvojeni razmakom, s ljestvicom, mala slova, najviše 30. Bez dijakritike.',
        'alt_text' => 'Kratki opis slike za osobe koje ne vide sliku, do 300 znakova.',
        'note' => 'Ako neki podatak nedostaje ili je sumnjiv, napiši to ovdje. Inače prazno.',
    ];

    public string $facebook;

    public string $instagram;

    public string $tiktok;

    public string $hashtags;

    public string $alt_text;

    public ?string $note = null;

    /**
     * A strict JSON schema: every field required (the note may be null) and nothing else allowed.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $properties = [];

        foreach (self::FIELDS as $field => $description) {
            $properties[$field] = ['type' => $field === 'note' ? ['string', 'null'] : 'string', 'description' => $description];
        }

        return [
            'type' => 'object',
            'description' => 'Tekstovi objave za jednu stavku sadržaja.',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws CaptionWriterException
     */
    public static function fromArray(array $data): self
    {
        $set = new self;

        foreach (['facebook', 'instagram', 'tiktok', 'hashtags', 'alt_text'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw new CaptionWriterException("Model nije vratio očekivanu strukturu (nedostaje „{$field}“).");
            }

            $set->{$field} = $data[$field];
        }

        $set->note = is_string($data['note'] ?? null) ? $data['note'] : null;

        return $set;
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
