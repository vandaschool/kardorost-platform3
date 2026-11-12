<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [...parent::share($request), 'unreadNotifications' => fn () => $user?->unreadNotifications()->count() ?? 0, 'activeRole' => $request->session()->get('panel_role', $user?->roleKeys()[0] ?? 'student'), 'auth' => ['user' => $user?->only('id', 'name', 'phone'), 'roles' => $user?->roleKeys() ?? []], 'flash' => ['success' => fn () => $request->session()->get('success'), 'error' => fn () => $request->session()->get('error')]];
    }
}
