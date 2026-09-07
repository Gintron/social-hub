<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DraftsAwaitingApproval extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $titles
     */
    public function __construct(public readonly Brand $brand, public readonly array $titles) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(sprintf('[Social Hub] %d nacrt(a) čeka odobrenje — %s', count($this->titles), $this->brand->name));

        foreach (array_slice($this->titles, 0, 10) as $title) {
            $mail->line('• '.$title);
        }

        return $mail->action('Pregledaj i odobri', url('/admin/post-drafts?tableFilters[status][value]=pending_approval'));
    }
}
