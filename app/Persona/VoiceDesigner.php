<?php

declare(strict_types=1);

namespace App\Persona;

use App\Voiceover\ElevenLabsClient;
use App\Voiceover\VoiceoverException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The character's own voice, made from a description: ElevenLabs offers three, a person listens and keeps one, and the
 * kept one is a voice in the account like any other — the brand's Voice-over tab lists it and every short speaks with it.
 */
final class VoiceDesigner
{
    public const DISK = 'public';

    public const FOLDER = 'brands/persona/voices';

    public function __construct(private readonly ElevenLabsClient $client) {}

    /**
     * @return list<array{id: string, path: string}> The generated voice id and its sample on the public disk.
     *
     * @throws VoiceoverException
     */
    public function propose(string $description): array
    {
        $description = mb_trim($description);

        if (mb_strlen($description) < 20) {
            throw new VoiceoverException('Opiši glas s barem nekoliko riječi (spol, dob, naglasak, tempo, toplina).', 'rejected');
        }

        $previews = [];

        foreach ($this->client->designVoice(mb_substr($description, 0, 1000)) as $preview) {
            $path = self::FOLDER.'/'.Str::uuid().'.mp3';
            Storage::disk(self::DISK)->put($path, $preview['audio']);
            $previews[] = ['id' => $preview['generated_voice_id'], 'path' => $path];
        }

        return $previews;
    }

    /**
     * @return string The new voice's id.
     *
     * @throws VoiceoverException
     */
    public function keep(string $generatedVoiceId, string $name, string $description): string
    {
        return $this->client->createVoice($generatedVoiceId, $name, $description);
    }
}
