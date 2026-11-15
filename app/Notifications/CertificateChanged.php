<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CertificateChanged extends Notification
{
    public function __construct(private string $title, private int $enrollmentId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'text' => 'وضعیت درخواست و گواهی را در بخش گواهی دوره ببینید.', 'url' => route('certificates.enrollment', $this->enrollmentId)];
    }
}
