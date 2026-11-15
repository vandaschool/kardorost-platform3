<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseDiscussion;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\DiscussionChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourseDiscussions
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['discussion' => $message]);
    }

    public function staff(User $user, Cohort $cohort): bool
    {
        return $user->is_active && ($user->hasRole('admin') || ($user->hasRole('coach') && $cohort->course->coach_id === $user->id));
    }

    public function access(User $user, Cohort $cohort): void
    {
        abort_unless($user->is_active && ($this->staff($user, $cohort) || ($user->phone_verified_at && $user->hasRole('student') && Enrollment::where('cohort_id', $cohort->id)->where('user_id', $user->id)->where('status', 'active')->exists())), 403);
    }

    public function visible(User $user, CourseDiscussion $discussion): void
    {
        $this->access($user, $discussion->cohort);
        abort_if($discussion->hidden && $discussion->author_id !== $user->id && ! $this->staff($user, $discussion->cohort), 404);
    }

    /** Parent locks also serialize enrollment/coach changes with message writes. */
    private function locked(User $user, Cohort $cohort): array
    {
        $course = Course::whereKey($cohort->course_id)->lockForUpdate()->firstOrFail();
        $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
        $cohort->setRelation('course', $course);
        Enrollment::where('cohort_id', $cohort->id)->where('user_id', $user->id)->lockForUpdate()->first();
        $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $this->access($user, $cohort);

        return [$user, $cohort];
    }

    private function text(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 12000) {
            $this->fail('متن پیام باید بین یک تا ۱۲۰۰۰ نویسه باشد.');
        }

        return $body;
    }

    private function audit(User $user, string $action, CourseDiscussion $discussion, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $user->id, 'action' => $action, 'subject_type' => 'course_discussion', 'subject_id' => $discussion->id, 'metadata' => $metadata]);
    }

    private function record(DiscussionPost $post, User $actor, ?string $reason = null): void
    {
        $post->revisions()->create(['actor_id' => $actor->id, 'revision' => $post->revision, 'body' => $post->body, 'author_role' => $post->author_role, 'hidden' => $post->hidden, 'reason' => $reason]);
    }

    private function newPost(User $user, Cohort $cohort, CourseDiscussion $discussion, string $body, string $key): DiscussionPost
    {
        $post = $discussion->posts()->create(['author_id' => $user->id, 'author_role' => $user->hasRole('admin') ? 'admin' : ($this->staff($user, $cohort) ? 'coach' : 'student'), 'body' => $body, 'request_key' => $key]);
        $this->record($post, $user);

        return $post;
    }

    private function notify(CourseDiscussion $discussion, User $actor, string $title, ?DiscussionPost $post = null, bool $participants = false, ?int $recipient = null): void
    {
        $ids = collect([$discussion->author_id, $discussion->cohort->course->coach_id, $recipient]);
        if ($participants) {
            $ids = $ids->merge($discussion->posts()->where('hidden', false)->pluck('author_id'));
        }
        foreach (User::whereIn('id', $ids->filter()->unique())->whereKeyNot($actor->id)->where('is_active', true)->get() as $user) {
            if ($this->staff($user, $discussion->cohort) || ($user->phone_verified_at && $user->hasRole('student') && Enrollment::where('user_id', $user->id)->where('cohort_id', $discussion->cohort_id)->where('status', 'active')->exists())) {
                $user->notify(new DiscussionChanged($title, $discussion->id, $post?->id));
            }
        }
    }

    public function create(User $user, Cohort $cohort, array $data): CourseDiscussion
    {
        return DB::transaction(function () use ($user, $cohort, $data) {
            [$user, $cohort] = $this->locked($user, $cohort);
            $body = $this->text($data['body']);
            $title = trim($data['title']);
            if ($title === '' || mb_strlen($title) > 160 || ! in_array($data['kind'], ['question', 'discussion'], true)) {
                $this->fail('عنوان و نوع گفت‌وگو معتبر نیست.');
            }
            $key = strtolower($data['request_key']);
            $existing = CourseDiscussion::where('author_id', $user->id)->where('request_key', $key)->first();
            if ($existing) {
                $first = $existing->posts()->oldest('id')->firstOrFail();
                if ($existing->cohort_id !== $cohort->id || $existing->title !== $title || $existing->kind !== $data['kind'] || $first->body !== $body || $first->revision !== 1 || $existing->hidden) {
                    $this->fail('شناسه این درخواست قبلاً استفاده شده است؛ فرم تازه باز کنید.');
                }

                return $existing;
            }
            $discussion = CourseDiscussion::create(['cohort_id' => $cohort->id, 'author_id' => $user->id, 'title' => $title, 'kind' => $data['kind'], 'request_key' => $key, 'last_post_at' => now()]);
            $discussion->setRelation('cohort', $cohort);
            $this->newPost($user, $cohort, $discussion, $body, (string) Str::uuid());
            $this->audit($user, 'discussion.created', $discussion, ['sharing_policy' => 'active_cohort_members_v1']);
            $this->notify($discussion, $user, 'گفت‌وگوی تازه در فضای آموزشی دوره');

            return $discussion;
        }, 3);
    }

    public function reply(User $user, CourseDiscussion $discussion, string $body, string $key): DiscussionPost
    {
        return DB::transaction(function () use ($user, $discussion, $body, $key) {
            [$user, $cohort] = $this->locked($user, $discussion->cohort);
            $discussion = CourseDiscussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
            $discussion->setRelation('cohort', $cohort);
            $this->visible($user, $discussion);
            $body = $this->text($body);
            $key = strtolower($key);
            $existing = DiscussionPost::where('author_id', $user->id)->where('request_key', $key)->first();
            if ($existing) {
                if ($existing->course_discussion_id !== $discussion->id || $existing->body !== $body || $existing->revision !== 1 || $existing->hidden || $discussion->hidden) {
                    $this->fail('شناسه این پیام قبلاً استفاده شده است؛ فرم تازه باز کنید.');
                }

                return $existing;
            }
            if ($discussion->locked || $discussion->hidden) {
                $this->fail('ارسال پیام در این گفت‌وگو متوقف است.');
            }
            $post = $this->newPost($user, $cohort, $discussion, $body, $key);
            $discussion->update(['last_post_at' => now()]);
            $this->audit($user, 'discussion.replied', $discussion, ['post_id' => $post->id, 'sharing_policy' => 'active_cohort_members_v1']);
            $this->notify($discussion, $user, 'پاسخ تازه در گفت‌وگوی دوره', $post, true);

            return $post;
        }, 3);
    }

    private function clearAnswer(User $user, CourseDiscussion $discussion, DiscussionPost $post, bool $questionChanged = false): void
    {
        if ($discussion->accepted_post_id && ($discussion->accepted_post_id === $post->id || $questionChanged)) {
            $discussion->update(['accepted_post_id' => null, 'revision' => $discussion->revision + 1]);
            $this->audit($user, 'discussion.answer_cleared', $discussion, ['post_id' => $post->id]);
        }
    }

    public function edit(User $user, DiscussionPost $post, int $revision, string $body): void
    {
        DB::transaction(function () use ($user, $post, $revision, $body) {
            [$user, $cohort] = $this->locked($user, $post->discussion->cohort);
            $discussion = CourseDiscussion::whereKey($post->course_discussion_id)->lockForUpdate()->firstOrFail();
            $discussion->setRelation('cohort', $cohort);
            $this->visible($user, $discussion);
            $post = DiscussionPost::whereKey($post->id)->lockForUpdate()->firstOrFail();
            abort_unless($post->author_id === $user->id, 403);
            if ($post->revision !== $revision || $post->hidden || $discussion->hidden || $discussion->locked) {
                $this->fail('پیام تغییر کرده یا امکان ویرایش آن متوقف شده است.');
            }
            $body = $this->text($body);
            if ($post->body === $body) {
                return;
            }
            $post->update(['body' => $body, 'author_role' => $user->hasRole('admin') ? 'admin' : ($this->staff($user, $cohort) ? 'coach' : 'student'), 'revision' => $post->revision + 1]);
            $this->record($post, $user);
            $this->clearAnswer($user, $discussion, $post, $discussion->posts()->oldest('id')->value('id') === $post->id);
            $this->audit($user, 'discussion.post_edited', $discussion, ['post_id' => $post->id, 'revision' => $post->revision]);
            $this->notify($discussion, $user, 'یک پیام در گفت‌وگوی دوره ویرایش شد', $post, true);
        }, 3);
    }

    public function moderatePost(User $user, DiscussionPost $post, int $revision, bool $hidden, string $reason): void
    {
        DB::transaction(function () use ($user, $post, $revision, $hidden, $reason) {
            [$user, $cohort] = $this->locked($user, $post->discussion->cohort);
            abort_unless($this->staff($user, $cohort), 403);
            $discussion = CourseDiscussion::whereKey($post->course_discussion_id)->lockForUpdate()->firstOrFail();
            $discussion->setRelation('cohort', $cohort);
            $post = DiscussionPost::whereKey($post->id)->lockForUpdate()->firstOrFail();
            if ($post->revision !== $revision) {
                $this->fail('پیام قبلاً تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if ($post->hidden === $hidden) {
                return;
            }
            $post->update(['hidden' => $hidden, 'moderation_reason' => $reason, 'revision' => $post->revision + 1]);
            $this->record($post, $user, $reason);
            if ($hidden) {
                $this->clearAnswer($user, $discussion, $post);
            }
            $this->audit($user, 'discussion.post_moderated', $discussion, ['post_id' => $post->id, 'hidden' => $hidden, 'reason' => $reason]);
            $this->notify($discussion, $user, 'وضعیت نمایش یک پیام دوره تغییر کرد', null, false, $post->author_id);
        }, 3);
    }

    public function moderate(User $user, CourseDiscussion $discussion, array $data): void
    {
        DB::transaction(function () use ($user, $discussion, $data) {
            [$user, $cohort] = $this->locked($user, $discussion->cohort);
            abort_unless($this->staff($user, $cohort), 403);
            $discussion = CourseDiscussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
            $discussion->setRelation('cohort', $cohort);
            if ($discussion->revision !== (int) $data['revision']) {
                $this->fail('تنظیمات گفت‌وگو تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if ($discussion->hidden === (bool) $data['hidden'] && $discussion->locked === (bool) $data['locked'] && $discussion->pinned === (bool) $data['pinned']) {
                return;
            }
            $discussion->update(['hidden' => $data['hidden'], 'locked' => $data['locked'], 'pinned' => $data['pinned'], 'moderation_reason' => $data['reason'], 'revision' => $discussion->revision + 1]);
            $this->audit($user, 'discussion.moderated', $discussion, array_intersect_key($data, array_flip(['hidden', 'locked', 'pinned', 'reason'])));
            $this->notify($discussion, $user, 'وضعیت یک گفت‌وگوی آموزشی تغییر کرد');
        }, 3);
    }

    public function accept(User $user, CourseDiscussion $discussion, int $revision, ?int $postId): void
    {
        DB::transaction(function () use ($user, $discussion, $revision, $postId) {
            [$user, $cohort] = $this->locked($user, $discussion->cohort);
            $discussion = CourseDiscussion::whereKey($discussion->id)->lockForUpdate()->firstOrFail();
            $discussion->setRelation('cohort', $cohort);
            $this->visible($user, $discussion);
            abort_unless($discussion->author_id === $user->id || $this->staff($user, $cohort), 403);
            if ($discussion->kind !== 'question' || $discussion->hidden || $discussion->locked || $discussion->revision !== $revision) {
                $this->fail('وضعیت پرسش تغییر کرده یا امکان انتخاب پاسخ متوقف است.');
            }
            $post = $postId ? $discussion->posts()->whereKey($postId)->where('hidden', false)->first() : null;
            if ($postId && (! $post || $discussion->posts()->oldest('id')->value('id') === $postId)) {
                $this->fail('یک پاسخ قابل نمایش از همین پرسش انتخاب کنید.');
            }
            if ($discussion->accepted_post_id === $postId) {
                return;
            }
            $discussion->update(['accepted_post_id' => $postId, 'revision' => $discussion->revision + 1]);
            $this->audit($user, 'discussion.answer_selected', $discussion, ['post_id' => $postId]);
            if ($post) {
                $this->notify($discussion, $user, 'پاسخ منتخب یک پرسش آموزشی مشخص شد', $post, false, $post->author_id);
            }
        }, 3);
    }
}
