<?php

declare(strict_types=1);

namespace App\Persona;

use App\Video\FalClient;
use App\Video\FalException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures of the character from fal, to choose from: a few portraits made from how the character looks and where they
 * stand, or the chosen one changed by an instruction ("shorter hair", "a green sweater"). The picture is what fal moves
 * the lips of in every talking shot, so it is the character itself — the same face in the same place, upright for 9:16.
 *
 * Every picture is stored on the public disk (`brands/persona/candidates/…`) and only the one a person picks is the
 * character's; the others cost what they cost and stay as candidates.
 */
final class CharacterImages
{
    public const DISK = 'public';

    public const FOLDER = 'brands/persona/candidates';

    public function __construct(private readonly FalClient $fal) {}

    /**
     * @return list<string> Paths on the public disk, in the order fal made them.
     *
     * @throws FalException
     */
    public function propose(string $appearance, string $ambience, string $extra = ''): array
    {
        $prompt = implode(' ', array_filter([
            'Photorealistic vertical portrait of a fictional social media creator talking to the camera, chest-up, eye level, natural expression, like a smartphone video frame.',
            "The person: {$appearance}.",
            $ambience !== '' ? "Setting behind them: {$ambience}." : null,
            $extra !== '' ? $extra : null,
            'Soft natural light, sharp focus on the face, no text, no logos, no watermark, no other people.',
        ]));

        return $this->store($this->fal->run((string) config('fal.models.image'), [
            'prompt' => $prompt,
            'image_size' => 'portrait_16_9',
            'num_images' => max(1, min(4, (int) config('fal.image_candidates', 4))),
            'output_format' => 'jpeg',
        ]));
    }

    /**
     * The picture changed by an instruction, keeping the person and the place.
     *
     * @return list<string>
     *
     * @throws FalException
     */
    public function refine(string $path, string $instruction): array
    {
        $image = Storage::disk(self::DISK)->path($path);

        return $this->store($this->fal->run((string) config('fal.models.image_edit'), [
            'prompt' => mb_trim($instruction).' Keep the same person, face, framing and setting; change only what is asked.',
            'image_urls' => [FalClient::dataUri($image, (string) (mime_content_type($image) ?: 'image/jpeg'))],
            'image_size' => 'portrait_16_9',
            'num_images' => 2,
            'output_format' => 'jpeg',
        ]));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     *
     * @throws FalException
     */
    private function store(array $result): array
    {
        $paths = [];
        Storage::disk(self::DISK)->makeDirectory(self::FOLDER);

        foreach ((array) ($result['images'] ?? []) as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $path = self::FOLDER.'/'.Str::uuid().'.jpg';
            $this->fal->download($url, Storage::disk(self::DISK)->path($path));
            $paths[] = $path;
        }

        if ($paths === []) {
            throw new FalException('fal nije vratio nijednu sliku.', 'failed');
        }

        return $paths;
    }
}
