<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CoachProfile;
use App\Models\CoachProfilePublication;
use App\Models\CoachProfileSubmission;
use App\Models\CoachProfileWithdrawal;
use App\Models\User;
use App\Notifications\CoachProfileReviewed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CoachProfiles
{
    public function eligible(User $user): bool
    {
        return $user->is_active && $user->phone_verified_at !== null && $user->hasRole('coach');
    }

    public function visible(): Builder
    {
        return CoachProfile::where('public_enabled', true)->whereNotNull('current_publication_id')->whereHas('owner', fn ($q) => $q->where('is_active', true)->whereNotNull('phone_verified_at')->whereHas('roles', fn ($role) => $role->where('key', 'coach')))->with('currentPublication');
    }

    /** Only the current, consented snapshot bound to this public profile is returned. */
    public function publishedDefinition(CoachProfile $profile): ?array
    {
        try {
            $publication = $profile->currentPublication;
            if (! $profile->public_enabled || ! $publication || $publication->coach_profile_id !== $profile->id || ! $publication->consented_at) {
                return null;
            }
            $definition = $publication->definition;
            if (! is_array($definition) || ! hash_equals($publication->definition_hash, $this->hash($definition))) {
                return null;
            }

            return $definition;
        } catch (Throwable) {
            return null;
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['profile' => 'نسخه یا وضعیت پروفایل تغییر کرده است؛ صفحه را تازه کنید.']);
    }

    private function audit(User $actor, CoachProfile $profile, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'coach_profile.'.$action, 'subject_type' => 'coach_profile', 'subject_id' => $profile->id, 'metadata' => ['revision' => $profile->revision]]);
    }

    private function fields(array $input): array
    {
        foreach (['display_name', 'headline', 'bio', 'city'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }
        foreach (['focus_areas', 'languages'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $input[$key] = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $input[$key]);
            }
        }
        $data = Validator::make($input, ['display_name' => 'required|string|min:3|max:160', 'headline' => 'required|string|min:3|max:160', 'bio' => 'required|string|min:20|max:6000', 'city' => 'nullable|string|max:100', 'focus_areas' => 'required|array|list|min:1|max:12', 'focus_areas.*' => 'required|string|min:3|max:60|distinct', 'languages' => 'required|array|list|min:1|max:5', 'languages.*' => 'required|string|min:2|max:40|distinct'])->validate();

        $photo = app(CoachProfilePhotos::class)->reference($input['photo'] ?? null);
        $links = app(CoachProfileLinks::class)->normalize($input['links'] ?? null);

        return [...['display_name' => trim($data['display_name']), 'headline' => trim($data['headline']), 'bio' => trim($data['bio']), 'city' => trim($data['city'] ?? ''), 'focus_areas' => array_map('trim', $data['focus_areas']), 'languages' => array_map('trim', $data['languages'])], ...($photo ? ['photo' => $photo] : []), ...($links ? ['links' => $links] : [])];
    }

    private function hash(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function save(User $actor, array $input, int $revision): CoachProfile
    {
        $definition = $this->fields($input);

        return DB::transaction(function () use ($actor, $definition, $revision) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->eligible($actor), 403);
            $profile = CoachProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            if (($profile?->revision ?? 0) !== $revision) {
                $this->fail();
            }
            app(CoachProfilePhotos::class)->validateReference($actor, $definition['photo'] ?? null);
            if (! $profile) {
                $profile = CoachProfile::create(['user_id' => $actor->id, 'slug' => (string) Str::uuid(), 'draft' => $definition]);
            } else {
                $pending = $profile->latestSubmission;
                if ($pending?->status === 'pending') {
                    $pending->update(['status' => 'withdrawn', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'note' => 'پیش‌نویس تازه جایگزین شد.']);
                }
                $profile->update(['draft' => $definition, 'state' => 'draft', 'revision' => $profile->revision + 1]);
            }
            $this->audit($actor, $profile, 'draft_saved');

            return $profile;
        }, 3);
    }

    public function submit(User $actor, CoachProfile $profile, int $revision, bool $consented): void
    {
        DB::transaction(function () use ($actor, $profile, $revision, $consented) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->eligible($actor) && $profile->user_id === $actor->id, 403);
            $profile = CoachProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($profile->revision !== $revision || ! in_array($profile->state, ['draft', 'rejected'], true)) {
                $this->fail();
            }
            if (! $consented) {
                throw ValidationException::withMessages(['public_consent' => 'تأیید صریح نمایش عمومی همین نسخه لازم است.']);
            }
            app(CoachProfilePhotos::class)->validateReference($actor, $profile->draft['photo'] ?? null, true);
            $submission = CoachProfileSubmission::create(['coach_profile_id' => $profile->id, 'profile_revision' => $profile->revision, 'definition' => $profile->draft, 'definition_hash' => $this->hash($profile->draft), 'consented_at' => now()]);
            $profile->update(['latest_submission_id' => $submission->id, 'state' => 'submitted', 'revision' => $profile->revision + 1]);
            $this->audit($actor, $profile, 'submitted');
        }, 3);
    }

    public function review(User $actor, CoachProfile $profile, int $revision, string $action, ?string $note): void
    {
        $data = Validator::make(['action' => $action, 'note' => $note], ['action' => 'required|in:publish,reject', 'note' => 'required_if:action,reject|nullable|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($actor, $profile, $revision, $data) {
            $users = User::whereIn('id', [$actor->id, $profile->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            $owner = $users->get($profile->user_id);
            abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
            $profile = CoachProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $submission = CoachProfileSubmission::whereKey($profile->latest_submission_id)->lockForUpdate()->first();
            if ($profile->revision !== $revision || $profile->state !== 'submitted' || $submission?->status !== 'pending') {
                $this->fail();
            }
            if ($data['action'] === 'publish') {
                abort_unless($this->eligible($owner), 403);
                if (! $submission->consented_at || ! hash_equals($submission->definition_hash, $this->hash($submission->definition))) {
                    $this->fail();
                }
                app(CoachProfilePhotos::class)->validateReference($owner, $submission->definition['photo'] ?? null, true);
                $publication = CoachProfilePublication::create(['coach_profile_id' => $profile->id, 'coach_profile_submission_id' => $submission->id, 'number' => 1 + (int) $profile->publications()->max('number'), 'definition' => $submission->definition, 'definition_hash' => $submission->definition_hash, 'consented_at' => $submission->consented_at, 'published_by' => $actor->id, 'published_at' => now()]);
                $profile->update(['current_publication_id' => $publication->id, 'public_enabled' => true, 'public_published_at' => now(), 'state' => 'approved', 'revision' => $profile->revision + 1]);
                $submission->update(['status' => 'published']);
            } else {
                $profile->update(['state' => 'rejected', 'revision' => $profile->revision + 1]);
                $submission->update(['status' => 'rejected']);
            }
            $submission->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'note' => $data['note']]);
            $this->audit($actor, $profile, $data['action'] === 'publish' ? 'published' : 'rejected');
            $owner->notify(new CoachProfileReviewed);
        }, 3);
    }

    public function withdraw(User $actor, CoachProfile $profile, int $revision, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $profile, $revision, $reason) {
            $users = User::whereIn('id', [$actor->id, $profile->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            $owner = $users->get($profile->user_id);
            $owns = $actor->id === $profile->user_id;
            abort_unless($actor->is_active && ($owns || $actor->hasRole('admin')), 403);
            if (! $owns) {
                Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000'])->validate();
            }
            $profile = CoachProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($profile->revision !== $revision || (! $profile->public_enabled && $profile->state !== 'submitted')) {
                $this->fail();
            }
            $submission = $profile->latestSubmission;
            if ($submission?->status === 'pending') {
                $submission->update(['status' => 'withdrawn', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'note' => $reason ?: 'انصراف صاحب پروفایل از انتشار.']);
            }
            $profile->update(['public_enabled' => false, 'state' => 'draft', 'revision' => $profile->revision + 1]);
            CoachProfileWithdrawal::create(['coach_profile_id' => $profile->id, 'actor_id' => $actor->id, 'profile_revision' => $profile->revision, 'reason' => $reason ?: 'انصراف صاحب پروفایل از انتشار.']);
            $this->audit($actor, $profile, 'withdrawn');
            if (! $owns) {
                $owner->notify(new CoachProfileReviewed);
            }
        }, 3);
    }
}
