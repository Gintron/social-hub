<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a model put the stress in one line of a voice-over (see App\Voiceover\Accenter): the answer,
 * kept, so the line is marked the same way in every video.
 *
 * @property int $id
 * @property string $hash
 * @property string $text
 * @property list<array{word: int, at: int}> $marks
 * @property string $model
 */
final class VoiceoverAccent extends Model
{
    protected $fillable = ['hash', 'text', 'marks', 'model'];

    protected function casts(): array
    {
        return ['marks' => 'array'];
    }
}
