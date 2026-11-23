<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CoachProfilePhotoWithdrawn extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'عکس پروفایل برداشته شد', 'text' => 'مدیریت دریافت تازهٔ یکی از عکس‌های پروفایل شما را متوقف کرد. دلیل و سابقه در بخش عکس‌های شما ثبت شده است.', 'url' => route('coach.photos')];
    }
}
