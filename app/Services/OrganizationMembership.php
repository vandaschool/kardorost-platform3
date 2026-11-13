<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationInvitationImport;
use App\Models\User;
use App\Notifications\OrganizationInvited;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationMembership
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['invitation' => $message]);
    }

    public function manager(User $actor, Organization $organization): void
    {
        abort_unless($actor->is_active && $actor->hasRole('org_admin') && $organization->status === 'active' && $organization->members()->where('users.id', $actor->id)->wherePivot('kind', 'manager')->exists(), 403);
    }

    private function audit(User $actor, OrganizationInvitation $invitation, string $action): void
    {
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'organization_invitation.'.$action, 'subject_type' => 'organization_invitation', 'subject_id' => $invitation->id, 'metadata' => ['organization_id' => $invitation->organization_id, 'revision' => $invitation->revision, 'status' => $invitation->status]]);
    }

    private function notify(OrganizationInvitation $invitation, Organization $organization): void
    {
        $user = User::where('phone', $invitation->phone)->where('is_active', true)->whereNotNull('phone_verified_at')->whereHas('roles', fn ($query) => $query->where('key', 'student'))->first();
        $user?->notify(new OrganizationInvited($organization->name));
    }

    /** The caller holds the organization lock and has checked the manager. */
    private function createLocked(User $actor, Organization $organization, string $phone): bool
    {
        if ($organization->members()->where('users.phone', $phone)->exists() || OrganizationInvitation::where('organization_id', $organization->id)->where('phone', $phone)->exists()) {
            return false;
        }
        $invitation = OrganizationInvitation::create(['organization_id' => $organization->id, 'created_by' => $actor->id, 'phone' => $phone, 'expires_at' => now()->addDays(7)]);
        $this->audit($actor, $invitation, 'created');
        $this->notify($invitation, $organization);

        return true;
    }

    public function invite(User $actor, Organization $organization, string $phone): bool
    {
        $phone = Phone::normalize($phone);

        return DB::transaction(function () use ($actor, $organization, $phone) {
            $organization = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $this->manager($actor, $organization);

            return $this->createLocked($actor, $organization, $phone);
        }, 3);
    }

    public function change(User $actor, OrganizationInvitation $invitation, int $revision, string $action): void
    {
        DB::transaction(function () use ($actor, $invitation, $revision, $action) {
            $organization = Organization::whereKey($invitation->organization_id)->lockForUpdate()->firstOrFail();
            $this->manager($actor, $organization);
            $invitation = OrganizationInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($invitation->revision !== $revision || $invitation->status === 'accepted') {
                $this->fail('دعوت تغییر کرده یا قبلاً پذیرفته شده است.');
            }
            if ($action === 'revoke' && $invitation->status === 'pending') {
                $invitation->update(['status' => 'revoked', 'revision' => $invitation->revision + 1]);
            } elseif ($action === 'renew' && ($invitation->status !== 'pending' || ! $invitation->expires_at->isFuture())) {
                $invitation->update(['status' => 'pending', 'expires_at' => now()->addDays(7), 'responded_by' => null, 'responded_at' => null, 'revision' => $invitation->revision + 1]);
                $this->notify($invitation, $organization);
            } else {
                $this->fail('وضعیت دعوت برای این اقدام معتبر نیست.');
            }
            $this->audit($actor, $invitation, $action);
        }, 3);
    }

    public function respond(User $actor, OrganizationInvitation $invitation, int $revision, string $action): void
    {
        abort_unless(in_array($action, ['accept', 'decline'], true), 422);
        DB::transaction(function () use ($actor, $invitation, $revision, $action) {
            $organization = Organization::whereKey($invitation->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = OrganizationInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->is_active && $actor->phone_verified_at && $actor->hasRole('student') && $actor->phone === $invitation->phone && $organization->status === 'active', 403);
            $status = $action === 'accept' ? 'accepted' : 'declined';
            if ($invitation->status === $status && $invitation->responded_by === $actor->id) {
                return;
            }
            if ($invitation->revision !== $revision || $invitation->status !== 'pending' || ! $invitation->expires_at->isFuture()) {
                $this->fail('دعوت منقضی یا تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if ($action === 'accept' && ! $organization->members()->where('users.id', $actor->id)->exists()) {
                $organization->members()->attach($actor->id, ['kind' => 'member']);
            }
            $invitation->update(['status' => $status, 'responded_by' => $actor->id, 'responded_at' => now(), 'revision' => $invitation->revision + 1]);
            $this->audit($actor, $invitation, $action);
        }, 3);
    }

    private function parse(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || strlen($contents) > 1048576 || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            $this->fail('فایل باید CSV با کدگذاری UTF-8 و حداکثر یک مگابایت باشد.');
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents);
        rewind($stream);
        $rows = [];
        $phones = [];
        $invalid = 0;
        $duplicates = 0;
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (! $header || count($header) !== 1 || strtolower(trim((string) ($header[0] ?? ''))) !== 'phone') {
                $this->fail('ردیف اول باید فقط ستون phone باشد. فایل الگو را دریافت کنید.');
            }
            $rowNumber = 1;
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $rowNumber++;
                if ($row === [null] || (count($row) === 1 && trim($row[0]) === '')) {
                    continue;
                }
                if (count($rows) >= 100) {
                    $this->fail('هر فایل حداکثر صد ردیف غیرخالی می‌پذیرد.');
                }
                $phone = null;
                if (count($row) === 1 && mb_strlen($row[0]) <= 60) {
                    try {
                        $phone = Phone::normalize($row[0]);
                    } catch (ValidationException) {
                    }
                }
                if (! $phone) {
                    $status = 'invalid';
                    $invalid++;
                } elseif (in_array($phone, $phones, true)) {
                    $status = 'duplicate';
                    $duplicates++;
                } else {
                    $status = 'valid';
                    $phones[] = $phone;
                }
                $rows[] = ['row' => $rowNumber, 'value' => mb_substr(implode(' | ', $row), 0, 60), 'phone' => $phone, 'status' => $status];
            }
        } finally {
            fclose($stream);
        }
        if (! $rows) {
            $this->fail('فایل حداقل یک شماره لازم دارد.');
        }

        return ['rows' => $rows, 'phones' => $phones, 'invalid_count' => $invalid, 'duplicate_count' => $duplicates];
    }

    public function preview(User $actor, Organization $organization, UploadedFile $file): OrganizationInvitationImport
    {
        $this->manager($actor, $organization);
        $parsed = $this->parse($file);

        return DB::transaction(function () use ($actor, $organization, $parsed) {
            $organization = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $this->manager($actor, $organization);
            $import = OrganizationInvitationImport::create([...$parsed, 'organization_id' => $organization->id, 'created_by' => $actor->id, 'expires_at' => now()->addMinutes(15)]);
            AuditEvent::create(['actor_id' => $actor->id, 'action' => 'organization_import.previewed', 'subject_type' => 'organization_invitation_import', 'subject_id' => $import->id, 'metadata' => ['organization_id' => $organization->id]]);

            return $import;
        }, 3);
    }

    public function commit(User $actor, OrganizationInvitationImport $import): array
    {
        return DB::transaction(function () use ($actor, $import) {
            $organization = Organization::whereKey($import->organization_id)->lockForUpdate()->firstOrFail();
            $this->manager($actor, $organization);
            $import = OrganizationInvitationImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            abort_unless($import->created_by === $actor->id, 403);
            if ($import->committed_at) {
                return $import->result;
            }
            if (! $import->expires_at->isFuture() || $import->invalid_count > 0 || ! $import->phones) {
                $this->fail('پیش‌نمایش منقضی است یا ردیف نامعتبر دارد؛ فایل را اصلاح و دوباره بررسی کنید.');
            }
            $created = 0;
            foreach ($import->phones as $phone) {
                $created += (int) $this->createLocked($actor, $organization, $phone);
            }
            $result = ['created' => $created, 'skipped' => count($import->phones) - $created];
            $import->update(['committed_at' => now(), 'result' => $result]);
            AuditEvent::create(['actor_id' => $actor->id, 'action' => 'organization_import.committed', 'subject_type' => 'organization_invitation_import', 'subject_id' => $import->id, 'metadata' => ['organization_id' => $organization->id, ...$result]]);

            return $result;
        }, 3);
    }
}
