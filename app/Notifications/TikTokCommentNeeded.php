<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PostVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A TikTok post went out whose video says "the link is in the comment", and the hub could not leave that comment.
 * This is the text, to paste by hand as the first comment: what the video promised is kept, one way or the other.
 */
final class TikTokCommentNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PostVariant $variant,
        public readonly string $reason,
        public readonly string $text,
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
        $account = $this->variant->account?->name ?? 'TikTok račun';

        return (new MailMessage)
            ->subject("[Social Hub] Zalijepi prvi komentar na TikTok video ({$account})")
            ->line('Video je objavljen i kaže da je poveznica u komentaru, a hub komentar nije mogao ostaviti.')
            ->line($this->why())
            ->line('Zalijepi ovo kao prvi komentar:')
            ->line($this->text)
            ->line('Nacrt #'.$this->variant->post_draft_id.', varijanta #'.$this->variant->id.($this->variant->permalink ? ' · '.$this->variant->permalink : ''))
            ->action('Otvori nacrt', url('/admin/post-drafts/'.$this->variant->post_draft_id));
    }

    private function why(): string
    {
        return match ($this->reason) {
            'not_enabled' => 'Komentiranje preko API-ja nije uključeno (TIKTOK_BUSINESS_COMMENTS): aplikacija još nema dozvolu za komentare.',
            'hidden' => 'TikTok je ostavljeni komentar sakrio (najčešće kao spam), pa ga drugi ne vide.',
            'no_post_id' => 'TikTok u 45 minuta nije javio id objavljenog videa, pa nema na što komentirati.',
            'permission' => 'TikTok je odbio komentar: aplikaciji ili računu nedostaje dozvola za komentare (comment.list.manage); račun treba ponovno povezati nakon što aplikacija dobije dozvolu.',
            default => 'TikTok je odbio ili nije izvršio komentar ('.$this->reason.').',
        };
    }
}
