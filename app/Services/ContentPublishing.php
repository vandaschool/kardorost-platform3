<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ContentPage;
use App\Models\ContentPublication;
use App\Models\ContentSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContentPublishing
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content' => $message]);
    }

    public function admin(User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
    }

    public function definition(array $input): array
    {
        $document = null;
        if (array_key_exists('document', $input) && $input['document'] !== null) {
            $document = app(ContentDocument::class)->normalize($input['document']);
            $input['body'] = app(ContentDocument::class)->plain($document);
        }
        foreach (['title', 'excerpt', 'body', 'meta_description'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && ! mb_check_encoding($input[$key], 'UTF-8')) {
                $this->fail('متن محتوا معتبر نیست.');
            }
        }
        $valid = Validator::make($input, ['title' => 'required|string|max:160', 'excerpt' => 'required|string|max:320', 'body' => 'required|string|max:60000', 'meta_description' => 'nullable|string|max:160'])->validate();

        return [...['title' => trim($valid['title']), 'excerpt' => trim($valid['excerpt']), 'body' => trim($valid['body']), 'meta_description' => trim($valid['meta_description'] ?? '')], ...($document ? ['document' => $document] : [])];
    }

    public function create(User $actor, string $kind, string $slug, array $definition): ContentPage
    {
        $this->admin($actor);
        Validator::make(['kind' => $kind, 'slug' => $slug], ['kind' => 'required|in:home,page,article', 'slug' => 'required|string|min:3|max:80|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'])->validate();
        if (($kind === 'home') !== ($slug === 'home')) {
            $this->fail('صفحه اصلی باید نوع home و نشانی home داشته باشد؛ این نشانی برای انواع دیگر رزرو است.');
        }
        $definition = $this->definition($definition);
        try {
            return DB::transaction(function () use ($actor, $kind, $slug, $definition) {
                $this->lockAdmin($actor);
                app(ContentMediaLibrary::class)->validateDocument($definition, false);
                $page = ContentPage::create(['created_by' => $actor->id, 'kind' => $kind, 'slug' => $slug, 'draft' => $definition]);
                $this->audit($actor, $page, 'created');

                return $page;
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('این نوع و نشانی قبلاً ساخته شده است؛ همان محتوا را ویرایش کنید.');
        }
    }

    private function locked(User $actor, ContentPage $page, int $revision): ContentPage
    {
        $this->lockAdmin($actor);
        $page = ContentPage::lockForUpdate()->findOrFail($page->id);
        if ($page->revision !== $revision) {
            $this->fail('محتوا تغییر کرده است؛ صفحه را تازه کنید.');
        }

        return $page;
    }

    private function audit(User $actor, ContentPage $page, string $action, array $extra = []): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'content.'.$action, 'subject_type' => 'content_page', 'subject_id' => $page->id, 'metadata' => ['revision' => $page->revision, 'version' => $page->current_version, ...$extra]]);
    }

    public function draft(User $actor, ContentPage $page, int $revision, array $definition): void
    {
        $definition = $this->definition($definition);
        DB::transaction(function () use ($actor, $page, $revision, $definition) {
            $page = $this->locked($actor, $page, $revision);
            app(ContentMediaLibrary::class)->validateDocument($definition, false);
            $this->cancelPending($actor, $page, 'draft_changed');
            $page->update(['draft' => $definition, 'revision' => $page->revision + 1]);
            $this->audit($actor, $page, 'draft_saved');
        });
    }

    public function publish(User $actor, ContentPage $page, int $revision): ContentPublication
    {
        return DB::transaction(function () use ($actor, $page, $revision) {
            $page = $this->locked($actor, $page, $revision);
            $definition = $this->definition($page->draft);
            app(ContentMediaLibrary::class)->validateDocument($definition, true);
            if ($page->currentPublication?->content_hash === $this->hash($definition)) {
                $this->fail('این محتوا قبلاً منتشر شده است؛ برای نمایش دوباره، وضعیت نمایش را تغییر دهید.');
            }
            $this->cancelPending($actor, $page, 'published_now');

            return $this->writePublication($actor, $page, $definition);
        });
    }

    public function availability(User $actor, ContentPage $page, int $revision, bool $available): void
    {
        DB::transaction(function () use ($actor, $page, $revision, $available) {
            $page = $this->locked($actor, $page, $revision);
            if ($available && ! $page->current_version) {
                $this->fail('ابتدا یک نسخه منتشر کنید.');
            }
            if ($available) {
                app(ContentMediaLibrary::class)->validateDocument($page->currentPublication->definition, true);
            }
            $this->cancelPending($actor, $page, 'visibility_changed');
            $previous = $page->available;
            $page->update(['available' => $available, 'revision' => $page->revision + 1]);
            $this->audit($actor, $page, 'availability_changed', ['before' => $previous, 'after' => $available]);
        });
    }

    private function lockAdmin(User $actor): void
    {
        $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $this->admin($actor);
    }

    public function hash(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function cancelPending(User $actor, ContentPage $page, string $reason): void
    {
        foreach ($page->schedules()->where('status', 'pending')->lockForUpdate()->get() as $schedule) {
            $schedule->update(['status' => 'cancelled', 'reason' => $reason, 'closed_by' => $actor->id, 'closed_at' => now()]);
            $this->audit($actor, $page, 'schedule_cancelled', ['schedule_id' => $schedule->id, 'reason' => $reason]);
        }
    }

    private function writePublication(User $actor, ContentPage $page, array $definition, ?ContentSchedule $schedule = null): ContentPublication
    {
        $publication = $page->publications()->create(['published_by' => $actor->id, 'number' => $page->current_version + 1, 'definition' => $definition, 'content_hash' => $this->hash($definition), 'published_at' => now()]);
        $page->update(['current_version' => $publication->number, 'available' => true, 'revision' => $page->revision + 1]);
        $this->audit($actor, $page, 'published', $schedule ? ['schedule_id' => $schedule->id, 'source' => 'scheduled'] : []);

        return $publication;
    }

    public function schedule(User $actor, ContentPage $page, int $revision, CarbonImmutable $at, bool $reviewed): ContentSchedule
    {
        $at = $at->setTimezone(config('app.timezone'))->startOfMinute();
        if (! $reviewed || $at->lt(now()->addMinute()) || $at->gt(now()->addYear())) {
            $this->fail('تأیید صریح نسخه ذخیره‌شده و زمانی از یک دقیقه آینده تا یک سال لازم است.');
        }

        return DB::transaction(function () use ($actor, $page, $revision, $at) {
            $page = $this->locked($actor, $page, $revision);
            $definition = $this->definition($page->draft);
            app(ContentMediaLibrary::class)->validateDocument($definition, true);
            $hash = $this->hash($definition);
            if ($page->currentPublication?->content_hash === $hash) {
                $this->fail('همین نسخه از قبل منتشر شده است.');
            }
            $this->cancelPending($actor, $page, 'replaced');
            $page->update(['revision' => $page->revision + 1]);
            $schedule = $page->schedules()->create(['created_by' => $actor->id, 'source_revision' => $revision, 'page_revision' => $page->revision, 'definition' => $definition, 'definition_hash' => $hash, 'scheduled_for' => $at]);
            $this->audit($actor, $page, 'scheduled', ['schedule_id' => $schedule->id]);

            return $schedule;
        }, 3);
    }

    public function cancelSchedule(User $actor, ContentPage $page, int $revision, ContentSchedule $schedule): void
    {
        DB::transaction(function () use ($actor, $page, $revision, $schedule) {
            $page = $this->locked($actor, $page, $revision);
            $schedule = $page->schedules()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($schedule->status !== 'pending') {
                $this->fail('برنامه انتشار قبلاً رسیدگی شده است؛ صفحه را تازه کنید.');
            }
            $this->cancelPending($actor, $page, 'admin_cancelled');
            $page->update(['revision' => $page->revision + 1]);
        }, 3);
    }

    public function processSchedule(ContentSchedule $input): string
    {
        return DB::transaction(function () use ($input) {
            $actor = User::whereKey($input->created_by)->lockForUpdate()->firstOrFail();
            $page = ContentPage::whereKey($input->content_page_id)->lockForUpdate()->firstOrFail();
            $schedule = ContentSchedule::whereKey($input->id)->lockForUpdate()->firstOrFail();
            if ($schedule->status !== 'pending') {
                return 'already_handled';
            }
            if ($schedule->scheduled_for->gt(now())) {
                return 'pending';
            }
            $reason = null;
            if ($schedule->created_by !== $actor->id || $schedule->content_page_id !== $page->id || ! $actor->is_active || ! $actor->hasRole('admin')) {
                $reason = 'author_ineligible';
            } elseif ($page->revision !== $schedule->page_revision || $this->hash($page->draft) !== $schedule->definition_hash) {
                $reason = 'page_changed';
            } elseif ($this->hash($schedule->definition) !== $schedule->definition_hash) {
                $reason = 'snapshot_changed';
            } else {
                try {
                    $definition = $this->definition($schedule->definition);
                    app(ContentMediaLibrary::class)->validateDocument($definition, true);
                    if ($page->currentPublication?->content_hash === $this->hash($definition)) {
                        $reason = 'already_published';
                    }
                } catch (ValidationException) {
                    $reason = 'invalid_content';
                }
            }
            if ($reason) {
                $schedule->update(['status' => 'blocked', 'reason' => $reason, 'closed_at' => now()]);
                $page->update(['revision' => $page->revision + 1]);
                $this->audit($actor, $page, 'schedule_blocked', ['schedule_id' => $schedule->id, 'reason' => $reason]);

                return 'blocked';
            }
            $publication = $this->writePublication($actor, $page, $definition, $schedule);
            $schedule->update(['status' => 'published', 'closed_by' => $actor->id, 'closed_at' => now(), 'content_publication_id' => $publication->id]);

            return 'published';
        }, 3);
    }
}
