<?php

namespace App\Notifications;

use App\Models\PaymentReceipt;
use Illuminate\Notifications\Notification;

class ReceiptReviewed extends Notification
{
    public function __construct(private PaymentReceipt $receipt, private bool $allocated) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->receipt->status === 'approved' ? ($this->allocated ? 'رسید و پرداخت شما تأیید شد' : 'رسید تأیید شد؛ تخصیص پرداخت نیازمند بررسی است') : 'رسید شما رد شد', 'text' => $this->receipt->review_note ?? 'وضعیت را در بخش مالی ببینید.', 'url' => $this->receipt->payment->package_purchase_id ? route('package-purchases.show', $this->receipt->payment->package_purchase_id) : route('finance')];
    }
}
