<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AccountNeedsReconnect extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SocialAccount $account, public readonly string $reason) {}

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
            ->subject("[Social Hub] Račun {$this->account->name} treba ponovno povezati")
            ->line("Platforma: {$this->account->platform->label()}, brend: {$this->account->brand?->name}.")
            ->line("Razlog: {$this->reason}")
            ->action('Otvori račune', url('/admin/social-accounts'))
            ->line('Objave za ovaj račun su zaustavljene dok se ne poveže ponovno.');
    }
}
