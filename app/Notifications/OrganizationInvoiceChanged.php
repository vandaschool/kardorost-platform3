<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OrganizationInvoiceChanged extends Notification
{
    public function __construct(private int $invoiceId, private string $title) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'text' => 'جزئیات را در صورتحساب‌های سازمان ببینید.', 'url' => route('organization.invoices.show', $this->invoiceId)];
    }
}
