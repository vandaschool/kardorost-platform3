<?php

namespace App\Http\Controllers;

use App\Models\CareerResume;
use App\Models\ResumeVersion;
use App\Services\LinkedInGuide;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class LinkedInGuideController extends Controller
{
    public function show(Request $request, CareerResume $resume, ResumeVersion $version, LinkedInGuide $service): Response
    {
        $guide = $service->prepare($request->user(), $resume, $version);
        $roles = $guide['roles'];
        unset($guide['roles']);
        $role = $request->session()->get('panel_role');

        $response = Inertia::render('LinkedInGuide', ['guide' => $guide, 'role' => in_array($role, $roles, true) ? $role : ($roles[0] ?? 'student')])->toResponse($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function download(Request $request, CareerResume $resume, ResumeVersion $version, LinkedInGuide $service): HttpResponse
    {
        $guide = $service->prepare($request->user(), $resume, $version, true);

        return response($service->text($guide))->withHeaders(['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="kardorost-linkedin-resume-'.$resume->id.'-version-'.$guide['version']['number'].'.txt"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
