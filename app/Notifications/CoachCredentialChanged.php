<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CoachCredentialChanged extends Notification
{
    public function __construct(private int $credentialId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'بررسی داخلی پرونده خصوصی شما ثبت شد', 'text' => 'نتیجه و سابقه را در مدارک خصوصی خود ببینید.', 'url' => route('coach-credentials.show', $this->credentialId, false)];
    }
}
