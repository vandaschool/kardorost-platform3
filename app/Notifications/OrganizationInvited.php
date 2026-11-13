<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OrganizationInvited extends Notification
{
    public function __construct(private string $organization) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'دعوت به عضویت سازمان', 'text' => $this->organization.' شما را به عضویت دعوت کرده است. جزئیات دسترسی را پیش از پذیرش بخوانید.', 'url' => route('organization-invitations')];
    }
}
