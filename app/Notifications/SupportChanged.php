<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SupportChanged extends Notification
{
    public function __construct(private int $ticketId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'به‌روزرسانی درخواست خصوصی', 'text' => 'برای مشاهده وضعیت فعلی، درخواست پشتیبانی را باز کنید.', 'url' => route('support.show', $this->ticketId)];
    }
}
