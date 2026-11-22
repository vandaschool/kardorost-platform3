<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseDiscussion;
use App\Models\DiscussionAttachment;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\DiscussionChanged;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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
    private function locked(User $user, Cohort $cohort, bool $checkAccess = true): array
    {
        $course = Course::whereKey($cohort->course_id)->lockForUpdate()->firstOrFail();
        $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
        $cohort->setRelation('course', $course);
        Enrollment::where('cohort_id', $cohort->id)->where('user_id', $user->id)->lockForUpdate()->first();
        $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        abort_unless($user->is_active, 403);
        if ($checkAccess) {
            $this->access($user, $cohort);
        }

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
        $post->revisions()->create(['actor_id' => $actor->id, 'revision' => $post->revision, 'body' => $post->body, 'author_role' => $post->author_role, 'hidden' => $post->hidden, 'reason' => $reason, 'attachments' => $post->attachments()->whereNull('withdrawn_at')->get()->map(fn ($attachment) => $this->attachmentSnapshot($attachment))->all()]);
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

    public const ATTACHMENT_CONSENT = 'این فایل برای اعضای مجاز همین نوبت، مدرس فعلی و ادمین قابل دریافت است. اطلاعاتی را که اجازه اشتراک آن‌ها را ندارم بارگذاری نمی‌کنم.';

    public function attachmentMetadata(DiscussionAttachment $attachment): ?array
    {
        try {
            $data = $attachment->definition;
            if (! is_array($data) || ($data['attachment_id'] ?? null) !== $attachment->id || ($data['post_id'] ?? null) !== $attachment->discussion_post_id || ($data['owner_id'] ?? null) !== $attachment->owner_id || ($data['request_key'] ?? null) !== $attachment->request_key || ($data['file_path'] ?? null) !== $attachment->file_path || ($data['sharing_policy'] ?? null) !== 'active_cohort_members_v1') {
                return null;
            }
            foreach (['file_name', 'mime', 'sha256', 'consented_at', 'consent_text'] as $key) {
                if (! is_string($data[$key] ?? null) || ! mb_check_encoding($data[$key], 'UTF-8')) {
                    return null;
                }
            }
            if (! is_int($data['size'] ?? null) || $data['size'] < 1 || $data['size'] > 5242880 || ! preg_match('/^[a-f0-9]{64}$/D', $data['sha256']) || ! in_array($data['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'], true)) {
                return null;
            }

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    private function attachmentSnapshot(DiscussionAttachment $attachment): array
    {
        $metadata = $this->attachmentMetadata($attachment);

        return ['id' => $attachment->id, 'file_name' => $metadata['file_name'] ?? 'فایل در دسترس نیست', 'mime' => $metadata['mime'] ?? null, 'size' => $metadata['size'] ?? null];
    }

    public function attachmentData(DiscussionAttachment $attachment): array
    {
        $metadata = $this->attachmentMetadata($attachment);

        return [...$this->attachmentSnapshot($attachment), 'revision' => $attachment->revision, 'withdrawn' => $attachment->withdrawn_at !== null, 'unavailable' => $metadata === null, 'file_url' => $metadata && ! $attachment->withdrawn_at ? route('discussion-attachments.file', $attachment) : null];
    }

    public function attachmentReadable(User $user, DiscussionPost $post): bool
    {
        $discussion = $post->discussion;
        $staff = $this->staff($user, $discussion->cohort);

        return $user->is_active && ((! $discussion->hidden && ! $post->hidden) || $staff) && ($staff || ($user->phone_verified_at && $user->hasRole('student') && Enrollment::where('cohort_id', $discussion->cohort_id)->where('user_id', $user->id)->where('status', 'active')->exists()));
    }

    public function attach(User $user, DiscussionPost $post, array $input, UploadedFile $file): DiscussionAttachment
    {
        $path = null;
        try {
            return DB::transaction(function () use ($user, $post, $input, $file, &$path) {
                [$user, $cohort] = $this->locked($user, $post->discussion->cohort);
                $discussion = CourseDiscussion::whereKey($post->course_discussion_id)->lockForUpdate()->firstOrFail();
                $discussion->setRelation('cohort', $cohort);
                $this->visible($user, $discussion);
                $post = DiscussionPost::whereKey($post->id)->lockForUpdate()->firstOrFail();
                abort_unless($post->author_id === $user->id, 403);
                if ($post->hidden || $discussion->hidden || $discussion->locked) {
                    $this->fail('افزودن ضمیمه به این پیام متوقف است.');
                }
                $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'request_key' => 'required|uuid', 'confirmed' => 'required|accepted', 'sharing_policy' => 'required|in:active_cohort_members_v1'])->validate();
                Validator::make(['file' => $file], ['file' => 'required|file|mimes:pdf,png,jpg,jpeg,txt|extensions:pdf,png,jpg,jpeg,txt|max:5120'])->validate();
                $name = str_replace(['/', '\\'], '_', $file->getClientOriginalName());
                $name = Str::limit(trim(preg_replace('/\p{C}/u', '', $name) ?? ''), 160, '');
                $mime = $file->getMimeType();
                $size = $file->getSize();
                $hash = hash_file('sha256', $file->getRealPath());
                if ($name === '' || ! $hash || ! is_int($size) || $size < 1 || ! in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'], true)) {
                    $this->fail('نوع یا محتوای فایل معتبر نیست.');
                }
                $key = strtolower($data['request_key']);
                $existing = DiscussionAttachment::where('owner_id', $user->id)->where('request_key', $key)->first();
                if ($existing) {
                    $metadata = $this->attachmentMetadata($existing);
                    if ($existing->discussion_post_id !== $post->id || $existing->withdrawn_at || ! $metadata || $metadata['sha256'] !== $hash || $metadata['size'] !== $size || $metadata['file_name'] !== $name || $metadata['mime'] !== $mime) {
                        $this->fail('شناسه این بارگذاری قبلاً استفاده شده است؛ فرم تازه باز کنید.');
                    }

                    return $existing;
                }
                if ($post->revision !== (int) $data['revision']) {
                    $this->fail('پیام تغییر کرده است؛ پیش از بارگذاری صفحه را تازه کنید.');
                }
                if ($post->attachments()->whereNull('withdrawn_at')->count() >= 3 || $post->attachments()->count() >= 10) {
                    $this->fail('هر پیام حداکثر سه ضمیمه فعال و ده بارگذاری در سابقه خود دارد.');
                }
                $attachment = DiscussionAttachment::create(['discussion_post_id' => $post->id, 'owner_id' => $user->id, 'request_key' => $key]);
                $path = $file->store('discussion-attachments/'.$post->id.'/'.$attachment->id, 'learning');
                if (! $path) {
                    $this->fail('ذخیره فایل انجام نشد؛ دوباره تلاش کنید.');
                }
                $attachment->update(['file_path' => $path, 'definition' => ['attachment_id' => $attachment->id, 'post_id' => $post->id, 'owner_id' => $user->id, 'request_key' => $key, 'file_path' => $path, 'file_name' => $name, 'mime' => $mime, 'size' => $size, 'sha256' => $hash, 'sharing_policy' => 'active_cohort_members_v1', 'consented_at' => now()->toIso8601String(), 'consent_text' => self::ATTACHMENT_CONSENT]]);
                $post->increment('revision');
                $this->record($post, $user);
                $this->clearAnswer($user, $discussion, $post, $discussion->posts()->oldest('id')->value('id') === $post->id);
                $this->audit($user, 'discussion.attachment_added', $discussion, ['post_id' => $post->id, 'attachment_id' => $attachment->id, 'revision' => $post->revision, 'sharing_policy' => 'active_cohort_members_v1']);
                $this->notify($discussion, $user, 'ضمیمه تازه در گفت‌وگوی دوره', $post, true);

                return $attachment;
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('learning')->delete($path);
            }
            throw $exception;
        }
    }

    public function withdrawAttachment(User $user, DiscussionAttachment $attachment, array $input): void
    {
        DB::transaction(function () use ($user, $attachment, $input): void {
            $post = $attachment->post;
            [$user, $cohort] = $this->locked($user, $post->discussion->cohort, false);
            $discussion = CourseDiscussion::whereKey($post->course_discussion_id)->lockForUpdate()->firstOrFail();
            $discussion->setRelation('cohort', $cohort);
            $post = DiscussionPost::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $attachment = DiscussionAttachment::whereKey($attachment->id)->lockForUpdate()->firstOrFail();
            abort_unless($attachment->discussion_post_id === $post->id && ($attachment->owner_id === $user->id || $this->staff($user, $cohort)), 403);
            $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'reason' => 'nullable|string|max:2000'])->validate();
            if ($attachment->withdrawn_at) {
                return;
            }
            if ($attachment->revision !== (int) $data['revision']) {
                $this->fail('وضعیت ضمیمه تغییر کرده است؛ صفحه را تازه کنید.');
            }
            $reason = trim($data['reason'] ?? '');
            if ($attachment->owner_id !== $user->id && $reason === '') {
                $this->fail('برای برداشتن فایل عضو، دلیل رسیدگی لازم است.');
            }
            $attachment->update(['withdrawn_at' => now(), 'withdrawn_by' => $user->id, 'revision' => $attachment->revision + 1]);
            $post->increment('revision');
            $this->record($post, $user, $reason ?: 'برداشتن ضمیمه توسط صاحب فایل');
            $this->clearAnswer($user, $discussion, $post, $discussion->posts()->oldest('id')->value('id') === $post->id);
            $this->audit($user, 'discussion.attachment_withdrawn', $discussion, ['post_id' => $post->id, 'attachment_id' => $attachment->id, 'revision' => $attachment->revision, 'reason' => $reason]);
        }, 3);
    }

    /** @return array{stream: resource, filename: string} */
    public function downloadAttachment(User $user, DiscussionAttachment $attachment): array
    {
        $stream = null;
        try {
            return DB::transaction(function () use ($user, $attachment, &$stream): array {
                $post = $attachment->post;
                [$user, $cohort] = $this->locked($user, $post->discussion->cohort);
                $discussion = CourseDiscussion::whereKey($post->course_discussion_id)->lockForUpdate()->firstOrFail();
                $discussion->setRelation('cohort', $cohort);
                $post = DiscussionPost::whereKey($post->id)->lockForUpdate()->firstOrFail();
                $post->setRelation('discussion', $discussion);
                $attachment = DiscussionAttachment::whereKey($attachment->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->attachmentReadable($user, $post), 404);
                $metadata = $this->attachmentMetadata($attachment);
                abort_if($attachment->withdrawn_at || ! $metadata, 404);
                $path = $attachment->file_path;
                abort_unless(is_string($path) && str_starts_with($path, 'discussion-attachments/'.$post->id.'/'.$attachment->id.'/') && ! str_contains($path, '..') && Storage::disk('learning')->exists($path), 404);
                $stream = Storage::disk('learning')->readStream($path);
                abort_unless(is_resource($stream), 404);
                $hash = hash_init('sha256');
                $size = hash_update_stream($hash, $stream);
                abort_unless($size === $metadata['size'] && hash_equals($metadata['sha256'], hash_final($hash)), 404);
                rewind($stream);
                $this->audit($user, 'discussion.attachment_downloaded', $discussion, ['post_id' => $post->id, 'attachment_id' => $attachment->id]);

                return ['stream' => $stream, 'filename' => $metadata['file_name']];
            });
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($exception instanceof HttpException) {
                throw $exception;
            }
            abort(404);
        }
    }
}
