<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AnnouncementAudience
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['audience' => $message]);
    }

    public function normalize(?array $input, array $roles, bool $stored = false): ?array
    {
        if ($input === null) {
            return null;
        }
        $data = Validator::make($input, ['type' => 'required|in:all,organization', 'organization_id' => 'nullable|integer|min:1', 'label' => 'sometimes|string|max:255'])->validate();
        if ($data['type'] === 'all') {
            if ($stored || ! empty($data['organization_id']) || isset($data['label'])) {
                $this->fail('محدوده عمومی نمی‌تواند سازمان داشته باشد.');
            }

            return null;
        }
        if (empty($data['organization_id']) || $roles === [] || array_diff($roles, ['student', 'org_admin'])) {
            $this->fail('یک مجموعه و نقش دانشجوی عضو یا مدیر همان مجموعه را انتخاب کنید.');
        }
        $label = $stored ? ($data['label'] ?? '') : Organization::whereKey($data['organization_id'])->where('status', 'active')->value('name');
        if (! is_string($label) || trim($label) === '' || ! mb_check_encoding($label, 'UTF-8')) {
            $this->fail('مجموعه فعال معتبر انتخاب کنید.');
        }

        return ['type' => 'organization', 'organization_id' => (int) $data['organization_id'], 'label' => trim($label)];
    }

    private function organizationId(?array $audience): ?int
    {
        if ($audience === null) {
            return null;
        }
        if (($audience['type'] ?? null) !== 'organization' || ! filter_var($audience['organization_id'] ?? null, FILTER_VALIDATE_INT) || (int) $audience['organization_id'] < 1) {
            $this->fail('محدوده ذخیره‌شده معتبر نیست؛ ارسال انجام نشد.');
        }

        return (int) $audience['organization_id'];
    }

    public function lockForCreate(?array $audience): void
    {
        $id = $this->organizationId($audience);
        if ($id !== null) {
            Organization::whereKey($id)->sharedLock()->first();
        }
    }

    public function lock(int $id, ?array $newAudience = null): Announcement
    {
        $before = Announcement::whereKey($id)->firstOrFail()->audience;
        $ids = array_values(array_unique(array_filter([$this->organizationId($before), $this->organizationId($newAudience)])));
        if ($ids !== []) {
            Organization::whereIn('id', $ids)->orderBy('id')->sharedLock()->get();
        }
        $announcement = Announcement::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($announcement->audience !== $before) {
            $this->fail('محدوده اطلاعیه تغییر کرده است؛ صفحه را تازه کنید.');
        }

        return $announcement;
    }

    public function query(array $roles, ?array $audience = null): Builder
    {
        $audience = $this->normalize($audience, $roles, true);
        $query = User::where('is_active', true)->whereNotNull('phone_verified_at');
        if ($audience === null) {
            return $query->whereHas('roles', fn ($rolesQuery) => $rolesQuery->whereIn('key', $roles));
        }

        return $query->where(function ($query) use ($audience, $roles): void {
            foreach (['student' => 'member', 'org_admin' => 'manager'] as $role => $kind) {
                if (in_array($role, $roles, true)) {
                    $query->orWhere(function ($member) use ($audience, $role, $kind): void {
                        $member->whereHas('roles', fn ($roleQuery) => $roleQuery->where('key', $role))
                            ->whereHas('organizations', fn ($organization) => $organization->where('organizations.id', $audience['organization_id'])->where('organizations.status', 'active')->where('organization_user.kind', $kind));
                    });
                }
            }
        });
    }

    /** The caller holds the organization read lock before the announcement lock. */
    public function freeze(Announcement $announcement): void
    {
        $audience = $this->normalize($announcement->audience, $announcement->roles, true);
        if ($audience === null) {
            return;
        }
        foreach ($announcement->recipients()->orderBy('id')->lazyById(200)->chunk(200) as $recipients) {
            $members = $this->members($announcement, $recipients->pluck('user_id')->all());
            $rows = [];
            foreach ($recipients as $recipient) {
                $member = $members[$recipient->user_id] ?? null;
                if (! $member || ! $member->membership_key) {
                    $this->fail('عضویت هنگام ثبت مخاطبان تغییر کرد؛ ارسال انجام نشد.');
                }
                $recipient->scope_snapshot = ['type' => 'organization', 'announcement_id' => $announcement->id, 'recipient_id' => $recipient->id, 'user_id' => $recipient->user_id, 'organization_id' => $audience['organization_id'], 'membership_key' => $member->membership_key, 'kind' => $member->kind];
                $rows[] = $recipient->getAttributes();
            }
            DB::table('announcement_recipients')->upsert($rows, ['id'], ['scope_snapshot']);
        }
    }

    public function members(Announcement $announcement, array $users): array
    {
        $id = $this->organizationId($announcement->audience);
        if ($id === null || ! Organization::whereKey($id)->where('status', 'active')->exists()) {
            return [];
        }

        return DB::table('organization_user')->where('organization_id', $id)->whereIn('user_id', $users)->get(['user_id', 'membership_key', 'kind'])->keyBy('user_id')->all();
    }

    public function sameMembership(Announcement $announcement, AnnouncementRecipient $recipient, array $members): bool
    {
        $id = $this->organizationId($announcement->audience);
        if ($id === null) {
            return $recipient->scope_snapshot === null;
        }
        $snapshot = $recipient->scope_snapshot;
        $member = $members[$recipient->user_id] ?? null;

        return $member && is_array($snapshot) && ($snapshot['type'] ?? null) === 'organization'
            && ($snapshot['announcement_id'] ?? null) === $announcement->id
            && ($snapshot['recipient_id'] ?? null) === $recipient->id
            && ($snapshot['user_id'] ?? null) === $recipient->user_id
            && ($snapshot['organization_id'] ?? null) === $id
            && ($snapshot['membership_key'] ?? null) === $member->membership_key
            && ($snapshot['kind'] ?? null) === $member->kind;
    }
}
