<?php

namespace App\Http\Controllers;

use App\Models\CoachCredential;
use App\Services\CoachCredentials;
use App\Services\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoachCredentialController extends Controller
{
    private function item(CoachCredential $credential, CoachCredentials $service): array
    {
        $metadata = $service->metadata($credential);

        return [...$credential->only('id', 'user_id', 'status', 'revision', 'created_at', 'consented_at'), 'owner_name' => $credential->owner->name, 'title' => $metadata['title'] ?? 'پرونده خصوصی', 'issuer' => $metadata['issuer'] ?? '', 'note' => $metadata['note'] ?? '', 'file_name' => $metadata['file_name'] ?? null, 'file_size' => $metadata['size'] ?? null, 'unavailable' => $metadata === null, 'file_url' => $metadata ? route('coach-credentials.file', $credential) : null];
    }

    private function page(Request $request, CoachCredentials $service, bool $adminIndex, ?CoachCredential $credential = null): Response
    {
        $data = $request->validate(['status' => 'nullable|in:submitted,accepted,rejected,withdrawn', 'phone' => 'nullable|string|max:40', 'page' => 'nullable|integer|min:1|max:1000000', 'events_page' => 'nullable|integer|min:1|max:1000000']);
        $filters = ['status' => $data['status'] ?? '', 'phone' => $adminIndex && trim($data['phone'] ?? '') !== '' ? Phone::normalize($data['phone']) : ''];

        return DB::transaction(function () use ($request, $service, $adminIndex, $credential, $filters): Response {
            [$actor, $credential, $admin] = $service->lock($request->user(), $credential, adminIndex: $adminIndex);
            $browseAdmin = $adminIndex || ($admin && $credential && $credential->user_id !== $actor->id);
            $query = CoachCredential::with('owner:id,name')->when(! $browseAdmin, fn ($query) => $query->where('user_id', $actor->id))->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))->when($filters['phone'] !== '', fn ($query) => $query->whereHas('owner', fn ($owner) => $owner->where('phone', $filters['phone'])));
            $roles = $actor->roleKeys();
            $role = $browseAdmin ? 'admin' : ($actor->hasRole('coach') ? 'coach' : (in_array($request->session()->get('panel_role'), $roles, true) ? $request->session()->get('panel_role') : ($roles[0] ?? 'student')));
            $events = $credential?->events()->with('actor:id,name')->latest('id')->paginate(15, ['*'], 'events_page')->withQueryString()->through(fn ($event) => [...$event->only('id', 'action', 'from_status', 'to_status', 'revision', 'created_at'), 'actor_name' => $event->actor->name, 'reason' => $event->reasonText()]);

            return Inertia::render('CoachCredentials', ['role' => $role, 'admin' => (bool) $browseAdmin, 'can_review' => $admin && $credential !== null, 'own' => $credential?->user_id === $actor->id, 'can_submit' => ! $browseAdmin && $actor->hasRole('coach'), 'filters' => $filters, 'credentials' => $query->orderByDesc('id')->paginate(20)->appends(array_filter($filters))->through(fn ($row) => $this->item($row, $service)), 'selected' => $credential ? $this->item($credential, $service) : null, 'events' => $events, 'request_key' => (string) Str::uuid(), 'consent_text' => CoachCredentials::CONSENT]);
        });
    }

    public function index(Request $request, CoachCredentials $service): Response
    {
        return $this->page($request, $service, false);
    }

    public function admin(Request $request, CoachCredentials $service): Response
    {
        return $this->page($request, $service, true);
    }

    public function show(Request $request, CoachCredential $credential, CoachCredentials $service): Response
    {
        return $this->page($request, $service, false, $credential);
    }

    public function store(Request $request, CoachCredentials $service): RedirectResponse
    {
        $request->validate(['file' => 'required|file']);
        $credential = $service->submit($request->user(), $request->only('title', 'issuer', 'note', 'consent', 'request_key'), $request->file('file'));

        return redirect()->route('coach-credentials.show', $credential)->with('success', 'پرونده خصوصی ثبت شد؛ فایل عمومی نیست.');
    }

    public function change(Request $request, CoachCredential $credential, CoachCredentials $service): RedirectResponse
    {
        $service->change($request->user(), $credential, $request->only('revision', 'action', 'reason', 'reviewed'));

        return back()->with('success', 'تصمیم و سابقه پرونده ثبت شد.');
    }

    public function file(Request $request, CoachCredential $credential, CoachCredentials $service): StreamedResponse
    {
        $prepared = $service->download($request->user(), $credential);

        return response()->streamDownload(function () use ($prepared): void {
            try {
                fpassthru($prepared['stream']);
            } finally {
                fclose($prepared['stream']);
            }
        }, $prepared['filename'], ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
