<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CoachProfileReviewed extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'بررسی پروفایل حرفه‌ای', 'text' => 'وضعیت بررسی یا نمایش پروفایل حرفه‌ای شما به‌روز شد.', 'url' => route('coach.profile')];
    }
}
