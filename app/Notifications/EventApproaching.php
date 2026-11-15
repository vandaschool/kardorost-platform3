<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class EventApproaching extends Notification
{
    public function __construct(private int $reminderId, private string $occursAt, private string $kind) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->kind === 'consultation' ? 'یادآوری مشاوره پیش رو' : 'یادآوری کلاس پیش رو', 'text' => 'زمان و وضعیت فعلی رویداد آموزشی یا مشاوره خود را در پنل بررسی کنید.', 'event_at' => $this->occursAt, 'url' => route('reminders.open', $this->reminderId)];
    }
}
