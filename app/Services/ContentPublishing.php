<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ContentPage;
use App\Models\ContentPublication;
use App\Models\User;
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
        $valid = Validator::make($input, ['title' => 'required|string|max:160', 'excerpt' => 'required|string|max:320', 'body' => 'required|string|max:60000', 'meta_description' => 'nullable|string|max:160'])->validate();

        return ['title' => trim($valid['title']), 'excerpt' => trim($valid['excerpt']), 'body' => trim($valid['body']), 'meta_description' => trim($valid['meta_description'] ?? '')];
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
        $page = ContentPage::lockForUpdate()->findOrFail($page->id);
        $this->admin($actor);
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
            $page->update(['draft' => $definition, 'revision' => $page->revision + 1]);
            $this->audit($actor, $page, 'draft_saved');
        });
    }

    public function publish(User $actor, ContentPage $page, int $revision): ContentPublication
    {
        return DB::transaction(function () use ($actor, $page, $revision) {
            $page = $this->locked($actor, $page, $revision);
            $definition = $this->definition($page->draft);
            $hash = hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($page->currentPublication?->content_hash === $hash) {
                $this->fail('این محتوا قبلاً منتشر شده است؛ برای نمایش دوباره، وضعیت نمایش را تغییر دهید.');
            }
            $publication = $page->publications()->create(['published_by' => $actor->id, 'number' => $page->current_version + 1, 'definition' => $definition, 'content_hash' => $hash, 'published_at' => now()]);
            $page->update(['current_version' => $publication->number, 'available' => true, 'revision' => $page->revision + 1]);
            $this->audit($actor, $page, 'published');

            return $publication;
        });
    }

    public function availability(User $actor, ContentPage $page, int $revision, bool $available): void
    {
        DB::transaction(function () use ($actor, $page, $revision, $available) {
            $page = $this->locked($actor, $page, $revision);
            if ($available && ! $page->current_version) {
                $this->fail('ابتدا یک نسخه منتشر کنید.');
            }
            $previous = $page->available;
            $page->update(['available' => $available, 'revision' => $page->revision + 1]);
            $this->audit($actor, $page, 'availability_changed', ['before' => $previous, 'after' => $available]);
        });
    }
}
