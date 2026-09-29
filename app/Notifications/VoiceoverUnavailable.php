<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The voice could not be produced for a video that was meant to have one — a wrong key, a used-up plan,
 * a voice that no longer exists. The video went out without it; this is how a person learns why the
 * narration stopped, before a week of posts has passed in silence.
 */
final class VoiceoverUnavailable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Brand $brand,
        public readonly string $errorCode,
        public readonly string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[Social Hub] Voice-over za {$this->brand->name} ne radi")
            ->line('Video je objavljen bez glasa (samo s glazbom, ako je brend ima). Objave se ne zaustavljaju.')
            ->line("Razlog: {$this->reason} [{$this->errorCode}]")
            ->line(match ($this->errorCode) {
                'quota_exceeded' => 'Plan na ElevenLabsu nema dovoljno znakova: pričekaj obnovu ili nadogradi plan.',
                'invalid_key', 'missing_permissions' => 'Provjeri ELEVENLABS_API_KEY na poslužitelju i da ključ smije koristiti text-to-speech.',
                'voice_not_found' => 'Odaberi glas ponovno u postavkama brenda (Voice-over).',
                'accents_quota_exceeded' => 'Na OpenAI računu nema kredita (Billing): dopuni ga, ili isključi naglaske na brendu (Voice-over → Naglasci). Bez naglasaka glas se ne radi.',
                'accents_invalid_key', 'accents_forbidden', 'accents_not_configured' => 'Provjeri OPENAI_API_KEY na poslužitelju i da projekt smije koristiti model naglasaka. Bez naglasaka glas se ne radi.',
                'accents_model_not_found' => 'Model naglasaka (OPENAI_ACCENT_MODEL, inače OPENAI_MODEL) ne postoji ili ga ključ ne smije koristiti: provjeri ime modela (hub:doctor).',
                default => 'Provjeri postavke u Brendovi → Voice-over.',
            })
            ->action('Otvori brendove', url('/admin/brands'));
    }
}
