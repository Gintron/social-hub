<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Voiceover;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A spoken clip for a hub admin to listen to — a sample of a voice from the brand form. The clips live
 * on a private disk; this is the one way a person reaches them, behind the panel's login.
 */
final class VoiceoverAudioController extends Controller
{
    public function __invoke(Request $request, Voiceover $voiceover): BinaryFileResponse
    {
        abort_unless($request->user()?->isHubAdmin(), 403);
        abort_unless($voiceover->fileExists(), 404);

        return response()->file($voiceover->absolutePath(), [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
