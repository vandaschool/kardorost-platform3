<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationMembershipController extends Controller
{
    private function history(?int $organizationId = null, ?int $memberId = null): array
    {
        return AuditEvent::where('action', 'like', 'organization_membership.%')->when($organizationId, fn ($q) => $q->where('metadata->organization_id', $organizationId))->when($memberId, fn ($q) => $q->where('subject_type', 'user')->where('subject_id', $memberId))->latest('id')->limit(50)->get()->map(fn ($event) => ['id' => $event->id, 'action' => $event->action, 'organization_name' => $event->metadata['organization_name'], 'member_name' => $event->metadata['member_name'], 'reason' => $event->metadata['reason'], 'created_at' => $event->created_at])->all();
    }

    public function mine(Request $request): Response
    {
        return Inertia::render('OrganizationMemberships', ['memberships' => $request->user()->organizations()->get(['organizations.id', 'name', 'type', 'status'])->map(fn ($org) => [...$org->only('id', 'name', 'type', 'status'), 'kind' => $org->pivot->kind, 'membership_key' => $org->pivot->membership_key]), 'history' => $this->history(memberId: $request->user()->id), 'role' => $request->user()->hasRole($request->session()->get('panel_role', 'student')) ? $request->session()->get('panel_role', 'student') : $request->user()->roleKeys()[0]]);
    }

    public function leave(Request $request, Organization $organization, OrganizationMembership $membership): RedirectResponse
    {
        $data = $request->validate(['membership_key' => 'required|uuid', 'reason' => 'required|string|max:2000']);
        $membership->endMembership($request->user(), $organization, $request->user(), $data['membership_key'], $data['reason'], 'leave');

        return back()->with('success', 'عضویت پایان یافت و دسترسی گزارش سازمان قطع شد.');
    }

    public function remove(Request $request, User $member, OrganizationMembership $membership): RedirectResponse
    {
        $organization = app(OrganizationController::class)->selected($request);
        abort_unless($organization, 403);
        $data = $request->validate(['membership_key' => 'required|uuid', 'reason' => 'required|string|max:2000']);
        $membership->endMembership($request->user(), $organization, $member, $data['membership_key'], $data['reason'], 'remove');

        return back()->with('success', 'عضویت پایان یافت. ثبت‌نام و سهمیه مصرف‌شده قبلی حفظ شدند.');
    }

    public function admin(Request $request): Response
    {
        $data = $request->validate(['organization' => 'nullable|integer|exists:organizations,id']);
        $organization = isset($data['organization']) ? Organization::findOrFail($data['organization']) : Organization::orderBy('id')->first();

        return Inertia::render('OrganizationManagers', ['organizations' => Organization::orderBy('name')->get(['id', 'name']), 'organization' => $organization?->only('id', 'name', 'status'), 'members' => $organization?->members()->get(['users.id', 'name', 'phone', 'is_active'])->map(fn ($user) => [...$user->only('id', 'name', 'phone', 'is_active'), 'kind' => $user->pivot->kind, 'membership_key' => $user->pivot->membership_key]) ?? [], 'history' => $organization ? $this->history($organization->id) : []]);
    }

    public function assign(Request $request, Organization $organization, OrganizationMembership $membership): RedirectResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:30', 'expected_key' => 'nullable|uuid']);
        $membership->assignManager($request->user(), $organization, $data['phone'], $data['expected_key'] ?? null);

        return back()->with('success', 'مدیر سازمان تعیین شد.');
    }

    public function adminRemove(Request $request, Organization $organization, User $member, OrganizationMembership $membership): RedirectResponse
    {
        $data = $request->validate(['membership_key' => 'required|uuid', 'reason' => 'required|string|max:2000', 'action' => 'required|in:remove,revoke_manager']);
        $membership->endMembership($request->user(), $organization, $member, $data['membership_key'], $data['reason'], $data['action']);

        return back()->with('success', 'دسترسی سازمانی فرد پایان یافت.');
    }
}
