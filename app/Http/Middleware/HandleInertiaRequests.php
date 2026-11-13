<?php

namespace App\Http\Middleware;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [...parent::share($request), 'organizationInvitationCount' => fn () => $user?->phone_verified_at && $user->hasRole('student') ? OrganizationInvitation::where('phone', $user->phone)->where('status', 'pending')->where('expires_at', '>', now())->whereHas('organization', fn ($query) => $query->where('status', 'active'))->count() : 0, 'unreadNotifications' => fn () => $user?->unreadNotifications()->count() ?? 0, 'activeRole' => $request->session()->get('panel_role', $user?->roleKeys()[0] ?? 'student'), 'auth' => ['user' => $user?->only('id', 'name', 'phone'), 'roles' => $user?->roleKeys() ?? []], 'flash' => ['success' => fn () => $request->session()->get('success'), 'error' => fn () => $request->session()->get('error')]];
    }
}
