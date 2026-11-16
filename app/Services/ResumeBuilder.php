<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CareerResume;
use App\Models\ResumeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResumeBuilder
{
    public const MAX_RESUMES = 20;

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['resume' => $message]);
    }

    public function authorize(User $actor, CareerResume $resume): void
    {
        $actor = $actor->fresh();
        abort_unless($actor->is_active && ($actor->id === $resume->user_id || $actor->hasRole('admin')), 403);
    }

    public function emptyDefinition(string $name): array
    {
        return ['locale' => 'fa', 'name' => $name, 'headline' => '', 'email' => '', 'phone' => '', 'city' => '', 'summary' => '', 'experience' => [], 'education' => [], 'projects' => [], 'skills' => [], 'links' => []];
    }

    public function definition(array $data): array
    {
        $valid = Validator::make($data, [
            'locale' => 'required|in:fa,en', 'name' => 'required|string|max:150', 'headline' => 'nullable|string|max:160', 'email' => 'nullable|email:rfc|max:200', 'phone' => 'nullable|string|max:50', 'city' => 'nullable|string|max:150', 'summary' => 'nullable|string|max:3000',
            'experience' => 'present|array|list|max:20', 'experience.*' => 'required|array:employer,title,period,description', 'experience.*.employer' => 'required|string|max:150', 'experience.*.title' => 'required|string|max:150', 'experience.*.period' => 'nullable|string|max:80', 'experience.*.description' => 'nullable|string|max:3000',
            'education' => 'present|array|list|max:15', 'education.*' => 'required|array:institution,degree,period,description', 'education.*.institution' => 'required|string|max:150', 'education.*.degree' => 'required|string|max:150', 'education.*.period' => 'nullable|string|max:80', 'education.*.description' => 'nullable|string|max:1500',
            'projects' => 'present|array|list|max:15', 'projects.*' => 'required|array:title,description', 'projects.*.title' => 'required|string|max:150', 'projects.*.description' => 'nullable|string|max:2000',
            'skills' => 'present|array|list|max:50', 'skills.*' => 'required|string|max:80',
            'links' => 'present|array|list|max:10', 'links.*' => 'required|array:label,url', 'links.*.label' => 'required|string|max:80', 'links.*.url' => 'required|url:http,https|max:2000',
        ])->validate();
        $result = [...$this->emptyDefinition($valid['name']), ...$valid];
        foreach (['name', 'headline', 'email', 'phone', 'city', 'summary'] as $key) {
            $result[$key] = trim($result[$key] ?? '');
        }
        $fields = ['experience' => ['employer' => '', 'title' => '', 'period' => '', 'description' => ''], 'education' => ['institution' => '', 'degree' => '', 'period' => '', 'description' => ''], 'projects' => ['title' => '', 'description' => ''], 'links' => ['label' => '', 'url' => '']];
        foreach ($fields as $key => $defaults) {
            $result[$key] = array_map(fn (array $entry) => array_map(fn ($value) => trim($value ?? ''), [...$defaults, ...$entry]), $result[$key]);
        }
        $result['skills'] = array_values(array_unique(array_map('trim', $result['skills'])));

        return $result;
    }

    public function snapshotDefinition(ResumeVersion $version): array
    {
        try {
            $definition = $version->definition;
            if (! is_array($definition) || $version->number < 1 || $this->definition($definition) !== $definition || ! hash_equals($version->content_hash, hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))) {
                $this->fail('نسخه ثابت رزومه برای این عملیات معتبر نیست.');
            }

            return $definition;
        } catch (\Throwable) {
            $this->fail('نسخه ثابت رزومه برای این عملیات معتبر نیست؛ با پشتیبانی پیگیری کنید.');
        }
    }

    private function accounts(User $actor, int $ownerId): User
    {
        User::whereIn('id', [$actor->id, $ownerId])->orderBy('id')->lockForUpdate()->get();
        $actor = $actor->fresh();
        abort_unless($actor->is_active && $actor->phone_verified_at, 403);

        return $actor;
    }

    public function locked(User $actor, CareerResume $resume): CareerResume
    {
        $actor = $this->accounts($actor, $resume->user_id);
        $resume = CareerResume::lockForUpdate()->findOrFail($resume->id);
        $this->authorize($actor, $resume);

        return $resume;
    }

    public function create(User $actor, string $title): CareerResume
    {
        $title = Validator::make(['title' => $title], ['title' => 'required|string|max:100'])->validate()['title'];

        return DB::transaction(function () use ($actor, $title) {
            $actor = $this->accounts($actor, $actor->id);
            if (CareerResume::where('user_id', $actor->id)->count() >= self::MAX_RESUMES) {
                $this->fail('حداکثر ۲۰ رزومه در هر حساب قابل نگهداری است؛ یکی از رزومه‌های موجود را ویرایش کنید.');
            }
            $resume = CareerResume::create(['user_id' => $actor->id, 'title' => trim($title), 'draft' => $this->emptyDefinition($actor->name)]);
            $this->audit($actor, $resume, 'created');

            return $resume;
        });
    }

    private function version(CareerResume $resume, int $revision, bool $editable = true): void
    {
        if ($resume->revision !== $revision) {
            $this->fail('رزومه تغییر کرده است؛ صفحه را تازه کنید.');
        }
        if ($editable && $resume->archived) {
            $this->fail('پیش از ویرایش، رزومه بایگانی‌شده را بازیابی کنید.');
        }
    }

    private function audit(User $actor, CareerResume $resume, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'resume.'.$action, 'subject_type' => 'career_resume', 'subject_id' => $resume->id, 'metadata' => ['owner_id' => $resume->user_id, 'revision' => $resume->revision, 'version' => $resume->current_version]]);
    }

    public function save(User $actor, CareerResume $resume, int $revision, string $title, array $definition): void
    {
        $title = Validator::make(['title' => $title], ['title' => 'required|string|max:100'])->validate()['title'];
        $definition = $this->definition($definition);
        DB::transaction(function () use ($actor, $resume, $revision, $title, $definition) {
            $resume = $this->locked($actor, $resume);
            $this->version($resume, $revision);
            $resume->update(['title' => trim($title), 'draft' => $definition, 'revision' => $resume->revision + 1]);
            $this->audit($actor, $resume, 'draft_saved');
        });
    }

    public function snapshot(User $actor, CareerResume $resume, int $revision): ResumeVersion
    {
        return DB::transaction(function () use ($actor, $resume, $revision) {
            $resume = $this->locked($actor, $resume);
            $this->version($resume, $revision);
            $definition = $this->definition($resume->draft);
            $hash = hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $current = $resume->versions()->where('number', $resume->current_version)->first();
            if ($current?->content_hash === $hash) {
                return $current;
            }
            $version = $resume->versions()->create(['created_by' => $actor->id, 'number' => $resume->current_version + 1, 'definition' => $definition, 'content_hash' => $hash]);
            $resume->update(['current_version' => $version->number, 'revision' => $resume->revision + 1]);
            $this->audit($actor, $resume, 'snapshot_created');

            return $version;
        });
    }

    public function archive(User $actor, CareerResume $resume, int $revision, bool $archived): void
    {
        DB::transaction(function () use ($actor, $resume, $revision, $archived) {
            $resume = $this->locked($actor, $resume);
            $this->version($resume, $revision, false);
            $resume->update(['archived' => $archived, 'revision' => $resume->revision + 1]);
            if ($archived) {
                app(ResumeSharing::class)->revokeForArchive($actor, $resume);
            }
            $this->audit($actor, $resume, $archived ? 'archived' : 'restored');
        });
    }
}
