<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ContentPage;
use App\Models\ContentRedirect;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContentRedirects
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['from_slug' => $message]);
    }

    private function lockedAdmin(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->is_active && $current->hasRole('admin'), 403);

        return $current;
    }

    private function validTarget(ContentPage $page): bool
    {
        return in_array($page->kind, ['article', 'page'], true)
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $page->slug)
            && mb_strlen($page->slug) <= 80
            && app(ContentPublishing::class)->publicDefinition($page) !== null;
    }

    private function canEnable(ContentPage $page, string $fromSlug): void
    {
        if (! $this->validTarget($page)) {
            $this->fail('مقصد باید مقاله یا صفحهٔ معتبر و منتشرشده با نمایش فعال باشد.');
        }
        if (ContentPage::where('kind', $page->kind)->where('slug', $fromSlug)->exists()) {
            $this->fail('این نشانی متعلق به محتواست؛ نشانی اصلی همیشه اولویت دارد.');
        }
    }

    private function audit(User $actor, ContentRedirect $redirect, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'content_redirect.'.$action, 'subject_type' => 'content_redirect', 'subject_id' => $redirect->id, 'metadata' => ['content_page_id' => $redirect->content_page_id, 'source_path' => $redirect->sourcePath(), 'enabled' => $redirect->enabled, 'revision' => $redirect->revision]]);
    }

    public function create(User $actor, ContentPage $page, string $fromSlug): ContentRedirect
    {
        app(ContentPublishing::class)->admin($actor);
        Validator::make(['from_slug' => $fromSlug], ['from_slug' => 'required|string|min:3|max:80|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'])->validate();
        try {
            return DB::transaction(function () use ($actor, $page, $fromSlug) {
                $actor = $this->lockedAdmin($actor);
                $page = ContentPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
                $this->canEnable($page, $fromSlug);
                if ($page->redirects()->count() >= 25) {
                    $this->fail('برای هر محتوا حداکثر بیست‌وپنج نشانی در سابقه نگه داشته می‌شود.');
                }
                $redirect = ContentRedirect::create(['content_page_id' => $page->id, 'kind' => $page->kind, 'from_slug' => $fromSlug, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                $this->audit($actor, $redirect, 'created');

                return $redirect;
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('این نشانی قبلاً ثبت شده است؛ همان رکورد را مدیریت کنید.');
        }
    }

    public function change(User $actor, ContentPage $page, ContentRedirect $redirect, int $revision, bool $enabled): void
    {
        app(ContentPublishing::class)->admin($actor);
        DB::transaction(function () use ($actor, $page, $redirect, $revision, $enabled) {
            $actor = $this->lockedAdmin($actor);
            $page = ContentPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $redirect = $page->redirects()->whereKey($redirect->id)->lockForUpdate()->firstOrFail();
            if ($redirect->revision !== $revision) {
                $this->fail('این رکورد تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if ($enabled) {
                if ($redirect->kind !== $page->kind) {
                    $this->fail('نوع نشانی و مقصد همخوان نیست.');
                }
                $this->canEnable($page, $redirect->from_slug);
            }
            if ($redirect->enabled === $enabled) {
                return;
            }
            $redirect->update(['enabled' => $enabled, 'revision' => $redirect->revision + 1, 'updated_by' => $actor->id]);
            $this->audit($actor, $redirect, $enabled ? 'enabled' : 'disabled');
        });
    }

    public function target(string $kind, string $slug): ?string
    {
        if (! in_array($kind, ['article', 'page'], true) || ContentPage::where('kind', $kind)->where('slug', $slug)->exists()) {
            return null;
        }
        $redirect = ContentRedirect::where('kind', $kind)->where('from_slug', $slug)->where('enabled', true)->with('page.currentPublication')->first();
        $page = $redirect?->page;
        if (! $page || $page->kind !== $kind || $page->slug === $slug || ! $this->validTarget($page)) {
            return null;
        }

        return $page->publicPath();
    }
}
