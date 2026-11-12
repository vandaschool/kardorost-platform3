<?php

namespace App\Notifications;

use App\Models\Submission;
use Illuminate\Notifications\Notification;

class AssignmentReviewed extends Notification
{
    public function __construct(private Submission $submission) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->submission->status === 'reviewed' ? 'بازخورد تمرین شما آماده است' : 'تمرین شما نیاز به اصلاح دارد', 'text' => $this->submission->assignment->title, 'url' => route('learning.room', ['enrollment' => $this->submission->enrollment_id, 'tab' => 'assignments']), 'submission_id' => $this->submission->id];
    }
}
