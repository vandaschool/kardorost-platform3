<?php

namespace App\Http\Controllers;

use App\Models\CareerResume;
use App\Models\ResumeShare;
use App\Models\ResumeVersion;
use App\Models\User;
use App\Services\ResumeBuilder;
use App\Services\ResumeSharing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ResumeShareController extends Controller
{
    public function index(Request $request, CareerResume $resume, ResumeVersion $version, ResumeSharing $service, ResumeBuilder $builder): Response
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:1000000']);

        return DB::transaction(function () use ($request, $resume, $version, $service, $builder): Response {
            [$actor, $resume, $version] = $service->context($request->user(), $resume, $version);
            try {
                $definition = $builder->snapshotDefinition($version);
            } catch (ValidationException) {
                $definition = null;
            }
            $owner = User::findOrFail($resume->user_id);
            $roles = $actor->roleKeys();
            $role = $request->session()->get('panel_role');
            $shares = ResumeShare::where('career_resume_id', $resume->id)->where('resume_version_id', $version->id)->where('user_id', $resume->user_id)->orderByDesc('id')->paginate(15)->through(function ($share) use ($service, $definition, $owner, $resume, $version): array {
                $data = $service->metadata($share);
                $status = $service->status($share);

                return [...$share->only('id', 'revision', 'include_contacts', 'duration_days', 'created_at', 'consented_at', 'expires_at', 'revoked_at'), 'status' => $status, 'available' => (bool) ($data && $definition && $service->matchesSource($data, $version, $definition) && ! $resume->archived && $owner->is_active && $owner->phone_verified_at && $status === 'active'), 'url' => $data ? route('public.resume', $data['token'], false) : null, 'reason' => $service->reason($share)];
            });

            return Inertia::render('ResumeSharing', ['resume' => $resume->only('id', 'revision', 'archived'), 'version' => [...$version->only('id', 'number', 'created_at'), 'definition' => $definition], 'shares' => $shares, 'own' => $actor->id === $resume->user_id, 'can_publish' => $actor->id === $resume->user_id && $actor->phone_verified_at !== null && ! $resume->archived && $definition !== null, 'role' => in_array($role, $roles, true) ? $role : ($roles[0] ?? 'student'), 'request_key' => (string) Str::uuid(), 'consent_text' => ResumeSharing::CONSENT]);
        });
    }

    public function publish(Request $request, CareerResume $resume, ResumeVersion $version, ResumeSharing $service): RedirectResponse
    {
        $service->publish($request->user(), $resume, $version, $request->only('request_key', 'revision', 'duration_days', 'include_contacts', 'reviewed', 'consent'));

        return redirect()->route('career.shares', ['resume' => $resume, 'version' => $version])->with('success', 'درخواست ثبت شد؛ وضعیت لینک را در فهرست بررسی کنید.');
    }

    public function revoke(Request $request, ResumeShare $share, ResumeSharing $service): RedirectResponse
    {
        $service->revoke($request->user(), $share, $request->only('revision', 'reason'));

        return back()->with('success', 'لینک قطع شد؛ نسخه‌های قبلاً دریافت‌شده از دستگاه گیرنده حذف نمی‌شوند.');
    }

    public function show(string $token, ResumeSharing $service): HttpResponse
    {
        $payload = $service->publicPayload($token);

        return response()->view('resume', [...$payload, 'shared' => true])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY']);
    }
}
