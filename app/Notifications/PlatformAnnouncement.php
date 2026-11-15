<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PlatformAnnouncement extends Notification
{
    public function __construct(private int $announcementId, private string $title, private string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['announcement_id' => $this->announcementId, 'title' => $this->title, 'text' => $this->message];
    }
}
