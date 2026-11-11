<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function show(Request $request, string $mode = 'login'): Response
    {
        abort_unless(in_array($mode, ['login', 'register', 'forgot-password']), 404);
        $flow = $request->session()->get('verification');

        return Inertia::render('Auth', ['mode' => $mode, 'step' => $flow && $flow['mode'] === $mode ? ($flow['verified_until'] > time() ? 'password' : 'code') : 'phone', 'phone' => $flow && $flow['mode'] === $mode ? $flow['phone'] : null]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:30', 'password' => 'required|string|max:200']);
        $phone = Phone::normalize($data['phone']);
        $key = 'login:'.$phone.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['phone' => 'تلاش‌های ورود زیاد است. چند دقیقه دیگر تلاش کنید.']);
        }
        RateLimiter::hit($key, 300);
        if (! Auth::attempt(['phone' => $phone, 'password' => $data['password'], 'is_active' => true])) {
            throw ValidationException::withMessages(['phone' => 'شماره موبایل یا رمز عبور درست نیست.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('panel');
    }

    public function send(Request $request, OtpService $otp): RedirectResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:30', 'mode' => 'required|in:register,forgot-password']);
        $phone = Phone::normalize($data['phone']);
        $mode = $data['mode'];
        $key = 'otp:'.$phone;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages(['phone' => 'برای ارسال دوباره یک دقیقه صبر کنید.']);
        }
        if (RateLimiter::tooManyAttempts('otp-day:'.$phone, 10)) {
            throw ValidationException::withMessages(['phone' => 'سقف ارسال روزانه پر شده است.']);
        }
        $exists = User::where('phone', $phone)->exists();
        if ($mode === 'register' && $exists) {
            throw ValidationException::withMessages(['phone' => 'این شماره حساب دارد. وارد شوید یا رمز را بازیابی کنید.']);
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit('otp-day:'.$phone, 86400);
        $challenge = ($mode === 'register' || $exists) ? $otp->issue($phone, $mode) : null;
        $request->session()->put('verification', ['phone' => $phone, 'mode' => $mode, 'id' => $challenge?->id, 'verified_until' => 0]);

        return redirect()->route($mode)->with('success', 'اگر شماره واجد شرایط باشد، کد ورود ارسال می‌شود.');
    }

    public function verify(Request $request, OtpService $otp): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|digits:5']);
        $flow = $request->session()->get('verification');
        if (! $flow || ! $flow['id'] || ! $otp->consume($flow['id'], $flow['phone'], $flow['mode'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'کد نادرست یا منقضی است. دوباره تلاش کنید.']);
        }
        $flow['verified_until'] = time() + 600;
        $request->session()->put('verification', $flow);

        return redirect()->route($flow['mode']);
    }

    public function complete(Request $request): RedirectResponse
    {
        $flow = $request->session()->get('verification');
        if (! $flow || $flow['verified_until'] < time()) {
            throw ValidationException::withMessages(['phone' => 'تأیید شماره منقضی شده است. دوباره شروع کنید.']);
        }
        $data = $request->validate(['name' => ($flow['mode'] === 'register' ? 'required' : 'nullable').'|string|max:100', 'password' => ['required', 'confirmed', Password::min(8)]]);
        $user = DB::transaction(function () use ($flow, $data) {
            if ($flow['mode'] === 'register') {
                if (User::where('phone', $flow['phone'])->exists()) {
                    throw ValidationException::withMessages(['phone' => 'این شماره قبلاً ثبت شده است.']);
                }
                $user = User::create(['name' => $data['name'], 'phone' => $flow['phone'], 'password' => $data['password'], 'phone_verified_at' => now()]);
                $user->roles()->attach(Role::where('key', 'student')->value('id'));
            } else {
                $user = User::where('phone', $flow['phone'])->where('is_active', true)->lockForUpdate()->firstOrFail();
                $user->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            AuditEvent::create(['actor_id' => $user->id, 'action' => $flow['mode'] === 'register' ? 'account.registered' : 'password.reset']);

            return $user;
        });
        $request->session()->forget('verification');
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('panel');
    }

    public function restart(Request $request): RedirectResponse
    {
        $mode = $request->session()->get('verification.mode', 'register');
        $request->session()->forget('verification');

        return redirect()->route($mode);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
