<?php

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Phone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('platform:grant-role {phone} {role}', function () {
    $key = $this->argument('role');
    $role = Role::where('key', $key)->first();
    if (! $role) {
        $this->error('نقش معتبر نیست.');

        return 1;
    }
    $phone = Phone::normalize($this->argument('phone'));
    $granted = DB::transaction(function () use ($phone, $role, $key): bool {
        $user = User::where('phone', $phone)->lockForUpdate()->first();
        if (! $user || ! $user->phone_verified_at) {
            return false;
        }
        $changes = $user->roles()->syncWithoutDetaching([$role->id]);
        if ($changes['attached'] !== []) {
            $user->increment('access_revision');
        }
        AuditEvent::create(['action' => 'role.granted', 'subject_type' => 'user', 'subject_id' => $user->id, 'metadata' => ['role' => $key, 'source' => 'console']]);

        return true;
    }, 3);
    if (! $granted) {
        $this->error('کاربر باید ابتدا ثبت‌نام و موبایلش را تأیید کند.');

        return 1;
    }
    $this->info('نقش اضافه شد.');

    return 0;
})->purpose('Grant an existing verified account a panel role from the server console');

Schedule::command('platform:expire-consultations')->everyMinute()->withoutOverlapping();

Schedule::command('platform:dispatch-invitation-sms')->everyMinute()->withoutOverlapping();

Schedule::command('platform:dispatch-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('platform:poll-sms-deliveries')->everyMinute()->withoutOverlapping();

Schedule::command('platform:dispatch-announcements')->everyMinute()->withoutOverlapping();

Schedule::command('platform:dispatch-heartbeats')->everyMinute()->withoutOverlapping();

Schedule::command('platform:publish-scheduled-content')->everyMinute()->withoutOverlapping();
