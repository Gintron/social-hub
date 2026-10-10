<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How a model said the words of one line of a voice-over are pronounced (see App\Voiceover\Phonetizer): the answer,
 * kept, so the line is transcribed the same way in every video.
 *
 * @property int $id
 * @property string $hash
 * @property string $text
 * @property list<array{word: int, ipa: string}> $marks
 * @property string $model
 */
final class VoiceoverTranscription extends Model
{
    protected $fillable = ['hash', 'text', 'marks', 'model'];

    protected function casts(): array
    {
        return ['marks' => 'array'];
    }
}
