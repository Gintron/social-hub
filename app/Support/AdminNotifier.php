<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Sends operational notifications to every configured hub admin.
 */
final class AdminNotifier
{
    public function notify(Notification $notification): void
    {
        $emails = (array) config('hub.admin_emails', []);

        if ($emails === []) {
            return;
        }

        NotificationFacade::route('mail', $emails)->notify($notification);
    }
}
