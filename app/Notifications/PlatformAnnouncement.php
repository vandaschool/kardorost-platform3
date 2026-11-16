<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class PlatformAnnouncement extends Notification
{
    public function __construct(private int $announcementId, private string $title, private string $message, private ?string $audienceLabel = null, private ?string $audienceType = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        if ($this->audienceLabel === null) {
            return ['announcement_id' => $this->announcementId, 'title' => $this->title, 'text' => $this->message];
        }
        $type = $this->audienceType ?? 'organization';
        if (! in_array($type, ['organization', 'cohort'], true)) {
            throw new \UnexpectedValueException;
        }
        $payload = ['engine' => $type.'_announcement_inbox_v1', 'audience_type' => $type, 'recipient_id' => (int) $notifiable->getKey(), 'announcement_id' => $this->announcementId, 'title' => $this->title, 'text' => $this->message, 'audience_label' => $this->audienceLabel];

        return ['announcement_id' => $this->announcementId, 'private_payload_version' => 1, 'private_payload' => Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    public static function display(array $data, int $recipientId): array
    {
        if (! array_key_exists('private_payload', $data) && ! array_key_exists('private_payload_version', $data)) {
            return $data;
        }
        try {
            if (($data['private_payload_version'] ?? null) !== 1 || ! is_string($data['private_payload'] ?? null)) {
                throw new \UnexpectedValueException;
            }
            $payload = json_decode(Crypt::decryptString($data['private_payload']), true, 512, JSON_THROW_ON_ERROR);
            $type = match ($payload['engine'] ?? null) {
                'organization_announcement_inbox_v1' => 'organization',
                'cohort_announcement_inbox_v1' => 'cohort',
                default => null,
            };
            if ($type === null || ($payload['audience_type'] ?? $type) !== $type || ($payload['recipient_id'] ?? null) !== $recipientId || ($payload['announcement_id'] ?? null) !== ($data['announcement_id'] ?? null)) {
                throw new \UnexpectedValueException;
            }
            foreach (['title' => 160, 'text' => 3000, 'audience_label' => $type === 'cohort' ? 512 : 255] as $key => $maximum) {
                if (! is_string($payload[$key] ?? null) || ! mb_check_encoding($payload[$key], 'UTF-8') || mb_strlen($payload[$key]) > $maximum) {
                    throw new \UnexpectedValueException;
                }
            }

            return ['announcement_id' => (int) ($data['announcement_id'] ?? 0), 'audience_type' => $type, ...array_intersect_key($payload, array_flip(['title', 'text', 'audience_label']))];
        } catch (Throwable) {
            return ['title' => 'اعلان خصوصی', 'text' => 'متن این اعلان قابل نمایش نیست؛ برای پیگیری با پشتیبانی تماس بگیرید.'];
        }
    }
}
