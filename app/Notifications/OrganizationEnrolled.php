<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OrganizationEnrolled extends Notification
{
    public function __construct(private string $course, private string $organization) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'ثبت‌نام سازمانی انجام شد', 'text' => $this->course.' با پوشش '.$this->organization.' به یادگیری شما اضافه شد.', 'url' => route('learning')];
    }
}
