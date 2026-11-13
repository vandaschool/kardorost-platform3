<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationInvitationImport;
use App\Services\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OrganizationInvitationController extends Controller
{
    private function selected(Request $request): Organization
    {
        $organization = app(OrganizationController::class)->selected($request);
        abort_unless($organization, 403);

        return $organization;
    }

    private function data(OrganizationInvitation $invitation): array
    {
        return [...$invitation->only('id', 'phone', 'status', 'revision', 'expires_at', 'responded_at', 'created_at'), 'expired' => ! $invitation->expires_at->isFuture()];
    }

    public function index(Request $request): Response
    {
        $organization = $this->selected($request);
        $data = $request->validate(['preview' => 'nullable|integer|min:1']);
        $import = isset($data['preview']) ? OrganizationInvitationImport::whereKey($data['preview'])->where('organization_id', $organization->id)->where('created_by', $request->user()->id)->firstOrFail() : null;

        return Inertia::render('OrganizationInvitations', ['organization' => $organization->only('id', 'name'), 'invitations' => OrganizationInvitation::where('organization_id', $organization->id)->latest('id')->paginate(20)->withQueryString()->through(fn ($invitation) => $this->data($invitation)), 'preview' => $import ? [...$import->only('id', 'rows', 'invalid_count', 'duplicate_count', 'expires_at', 'committed_at', 'result'), 'valid_count' => count($import->phones), 'expired' => ! $import->expires_at->isFuture()] : null]);
    }

    public function invite(Request $request, OrganizationMembership $membership): RedirectResponse
    {
        $organization = $this->selected($request);
        $data = $request->validate(['phone' => 'required|string|max:30']);
        $created = $membership->invite($request->user(), $organization, $data['phone']);

        return back()->with('success', $created ? 'دعوت هفت‌روزه ثبت شد؛ عضویت پس از پذیرش فرد انجام می‌شود.' : 'این شماره قبلاً عضو یا دارای دعوت است؛ وضعیت را در فهرست بررسی کنید.');
    }

    public function change(Request $request, OrganizationInvitation $invitation, OrganizationMembership $membership): RedirectResponse
    {
        $organization = $this->selected($request);
        abort_unless($organization->id === $invitation->organization_id, 403);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:revoke,renew']);
        $membership->change($request->user(), $invitation, $data['revision'], $data['action']);

        return back()->with('success', 'وضعیت دعوت به‌روز شد. عضویت پذیرفته‌شده تغییر نکرد.');
    }

    public function preview(Request $request, OrganizationMembership $membership): RedirectResponse
    {
        $organization = $this->selected($request);
        $request->validate(['file' => 'required|file|extensions:csv|max:1024']);
        $import = $membership->preview($request->user(), $organization, $request->file('file'));

        return redirect()->route('organization.invitations', ['preview' => $import->id]);
    }

    public function commit(Request $request, OrganizationInvitationImport $import, OrganizationMembership $membership): RedirectResponse
    {
        $organization = $this->selected($request);
        abort_unless($organization->id === $import->organization_id, 403);
        $request->validate(['reviewed' => 'required|accepted']);
        $result = $membership->commit($request->user(), $import);

        return back()->with('success', 'دعوت تازه: '.$result['created'].'؛ عضو یا دعوت قبلی: '.$result['skipped'].'. هیچ حساب یا عضویت خودکاری ساخته نشد.');
    }

    public function template(Request $request): HttpResponse
    {
        $this->selected($request);

        return response("\xEF\xBB\xBFphone\r\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="organization-invitations.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function mine(Request $request): Response
    {
        abort_unless($request->user()->phone_verified_at, 403);

        return Inertia::render('MyOrganizationInvitations', ['invitations' => OrganizationInvitation::where('phone', $request->user()->phone)->whereHas('organization', fn ($query) => $query->where('status', 'active'))->with('organization:id,name,type')->latest('id')->paginate(20)->through(fn ($invitation) => [...$this->data($invitation), 'organization' => $invitation->organization->only('id', 'name', 'type')])]);
    }

    public function respond(Request $request, OrganizationInvitation $invitation, OrganizationMembership $membership): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:accept,decline', 'consent' => 'exclude_unless:action,accept|required|accepted']);
        $membership->respond($request->user(), $invitation, $data['revision'], $data['action']);

        return back()->with('success', $data['action'] === 'accept' ? 'عضویت پذیرفته شد. دسترسی گزارش برای سازمان فعال شد؛ ثبت‌نام دوره جدا انجام می‌شود.' : 'دعوت رد شد؛ عضویتی ایجاد نشد.');
    }
}
