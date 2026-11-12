<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class RefundChanged extends Notification
{
    public function __construct(private string $status) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => match ($this->status) {
            'requested' => 'درخواست بازپرداخت ثبت شد','approved' => 'بازپرداخت تأیید شد؛ در انتظار واریز','rejected' => 'درخواست بازپرداخت رد شد','completed' => 'مدرک انجام بازپرداخت ثبت شد'
        }, 'text' => 'جزئیات و تاریخچه را در بخش بازپرداخت‌ها ببینید.', 'url' => route('refunds')];
    }
}
