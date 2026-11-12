<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ConsultationChanged extends Notification
{
    public function __construct(private string $title) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'text' => 'جزئیات را در بخش مشاوره ببینید.', 'url' => route('consultations')];
    }
}
