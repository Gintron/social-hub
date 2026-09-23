<?php

declare(strict_types=1);

namespace App\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Ai\Schemas\CaptionSet;
use App\Models\Brand;
use App\Models\ContentItem;
use Throwable;

/**
 * Captions from Claude, constrained by a JSON schema and a system prompt that carries the brand's
 * voice. The prompt is the stable part of the request and is cached; the item is the variable part.
 */
final class AnthropicCaptionWriter implements CaptionWriter
{
    public function __construct(private readonly Client $client) {}

    public function write(ContentItem $item, Brand $brand, array $violations = []): CaptionResult
    {
        $model = (string) config('hub.ai.model', 'claude-opus-5');

        try {
            $message = $this->client->messages->create(
                model: $model,
                maxTokens: (int) config('hub.ai.max_tokens', 16000),
                system: [[
                    'type' => 'text',
                    'text' => $this->system($brand),
                    // Identical for every item of this brand, so it is worth caching.
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                messages: [['role' => 'user', 'content' => $this->user($item, $violations)]],
                outputConfig: [
                    'format' => CaptionSet::class,
                    'effort' => (string) config('hub.ai.effort', 'medium'),
                ],
            );
        } catch (APIStatusException $e) {
            throw new CaptionWriterException('Claude API: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new CaptionWriterException('Poziv modela nije uspio: '.$e->getMessage(), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new CaptionWriterException('Model je odbio napisati objavu za ovu stavku.');
        }

        $captions = $message->parsedOutput();

        if (! $captions instanceof CaptionSet) {
            throw new CaptionWriterException('Model nije vratio očekivanu strukturu.');
        }

        return new CaptionResult(
            captions: $captions,
            model: $model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
        );
    }

    private function system(Brand $brand): string
    {
        $voice = $brand->voice ?? [];

        $lines = [
            "Pišeš objave za društvene mreže za brend „{$brand->name}\" ({$brand->site_url}).",
            'Odgovaraš isključivo na hrvatskom jeziku.',
            '',
            'TON I STIL',
            '- '.($voice['tone'] ?? 'jasan, prijateljski, bez pretjerivanja i bez marketinškog patosa'),
        ];

        if (filled($voice['rules'] ?? null)) {
            $lines[] = '- '.str_replace("\n", "\n- ", mb_trim((string) $voice['rules']));
        }

        if (filled($voice['cta'] ?? null)) {
            $lines[] = '- Poziv na akciju: '.$voice['cta'];
        }

        // The brand's own sentence is not an item fact, so rule 7 below would otherwise forbid it —
        // and on Instagram and TikTok it is the only reason a viewer is given to open the profile.
        if (filled($voice['pitch'] ?? null)) {
            $lines[] = '- Rečenica o brendu (Instagram i TikTok tekst je uključuju doslovno, prije upute na poveznicu u profilu; to je tekst brenda, ne podatak stavke): '.mb_trim((string) $voice['pitch']);
        }

        $fixed = (array) ($voice['hashtags'] ?? []);
        if ($fixed !== []) {
            $lines[] = '- Uvijek uključi ove hashtagove: '.implode(' ', array_map(fn (string $tag): string => '#'.mb_ltrim($tag, '#'), $fixed));
        }

        $lines = [...$lines, ...[
            '',
            'TVRDA PRAVILA (kršenje znači da se objava odbacuje)',
            '1. Koristi isključivo podatke iz poruke. Ne izmišljaj cijene, plaće, postotke, datume ni lokacije.',
            '2. Nijedan iznos, postotak ili valuta ne smije se pojaviti ako ga nema u podacima.',
            '3. Ne izmišljaj poveznice. Smiješ koristiti samo poveznicu koja je navedena u podacima.',
            '4. Facebook: najviše 63000 znakova. Instagram: najviše 2200 znakova.',
            '5. Najviše 30 hashtagova, sve malim slovima i bez dijakritike.',
            '6. Instagram tekst ne smije sadržavati poveznicu; umjesto nje uputi na poveznicu u opisu profila.',
            '7. Ne obećavaj ništa što u podacima ne piše (ne izmišljaj rokove, uvjete ni pogodnosti).',
            '8. TikTok tekst ne smije sadržavati poveznicu; prvi red mu je naslov do 90 znakova.',
            '',
            'KAKO DOBITI PREGLEDE',
            '- Prvi red svakog teksta je udica: što je posao/ponuda i najvažniji podatak iz podataka (iznos, mjesto). Ljudi odluče u prvih 125 znakova.',
            '- Kratke rečenice, obraćanje s „ti“, bez uvoda tipa „Tražimo…“ ili „Pozivamo…“.',
            '- Instagram i TikTok završavaju pozivom da se objava pošalje prijatelju kojem treba.',
        ]];

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $violations
     */
    private function user(ContentItem $item, array $violations): string
    {
        $payload = json_encode([
            'kind' => $item->kind->value,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'facts' => $item->facts,
            'badges' => $item->badges,
            'price' => $item->price,
            'body_text' => $item->body_text,
            'url' => $item->url,
            'cta' => $item->cta,
            'tags' => $item->tags,
            'expires_at' => $item->expires_at?->toIso8601ZuluString(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $prompt = "Napiši objave za ovu stavku:\n\n{$payload}";

        if ($violations !== []) {
            $prompt .= "\n\nPrethodni pokušaj je odbačen jer je prekršio ova pravila:\n- ".implode("\n- ", $violations)
                ."\n\nNapiši novu verziju koja ih ne krši.";
        }

        return $prompt;
    }
}
