<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PostVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PublishFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PostVariant $variant) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $draft = $this->variant->draft;

        return (new MailMessage)
            ->error()
            ->subject('[Social Hub] Objava nije uspjela: '.($draft?->title ?? "nacrt #{$this->variant->post_draft_id}"))
            ->line("Platforma: {$this->variant->platform->label()} ({$this->variant->account?->name}).")
            ->line('Greška: '.($this->variant->error_message ?? 'nepoznato').' ['.($this->variant->error_code ?? '-').']')
            ->action('Otvori nacrt', url("/admin/post-drafts/{$this->variant->post_draft_id}/edit"));
    }
}
