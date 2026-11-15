<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class DiscussionChanged extends Notification
{
    public function __construct(private string $title, private int $discussionId, private ?int $postId = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'text' => 'برای مشاهده وضعیت فعلی، گفت‌وگوی دوره را باز کنید.', 'url' => $this->postId ? route('discussions.locate', ['discussion' => $this->discussionId, 'post' => $this->postId]) : route('discussions.show', $this->discussionId)];
    }
}
