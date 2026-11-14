<?php

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Phone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('platform:grant-role {phone} {role}', function () {
    $key = $this->argument('role');
    $role = Role::where('key', $key)->first();
    if (! $role) {
        $this->error('نقش معتبر نیست.');

        return 1;
    }
    $user = User::where('phone', Phone::normalize($this->argument('phone')))->first();
    if (! $user || ! $user->phone_verified_at) {
        $this->error('کاربر باید ابتدا ثبت‌نام و موبایلش را تأیید کند.');

        return 1;
    }
    $user->roles()->syncWithoutDetaching([$role->id]);
    AuditEvent::create(['action' => 'role.granted', 'subject_type' => 'user', 'subject_id' => $user->id, 'metadata' => ['role' => $key, 'source' => 'console']]);
    $this->info('نقش اضافه شد.');

    return 0;
})->purpose('Grant an existing verified account a panel role from the server console');

Schedule::command('platform:expire-consultations')->everyMinute()->withoutOverlapping();

Schedule::command('platform:dispatch-invitation-sms')->everyMinute()->withoutOverlapping();
