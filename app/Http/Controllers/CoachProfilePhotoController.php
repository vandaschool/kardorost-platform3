<?php

namespace App\Http\Controllers;

use App\Models\CoachProfile;
use App\Models\CoachProfilePhoto;
use App\Services\CoachProfilePhotos;
use App\Services\CoachProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoachProfilePhotoController extends Controller
{
    public function index(Request $request, CoachProfilePhotos $photos, CoachProfiles $profiles): Response
    {
        $owner = $request->user()->fresh();

        return Inertia::render('CoachProfilePhotos', ['photos' => $photos->listing($owner, $owner->id), 'can_upload' => $profiles->eligible($owner), 'has_profile' => CoachProfile::where('user_id', $owner->id)->exists(), 'consent' => CoachProfilePhotos::CONSENT, 'role' => $request->session()->get('panel_role')]);
    }

    public function upload(Request $request, CoachProfilePhotos $photos): RedirectResponse
    {
        $request->validate(['file' => 'required|file']);
        $photos->upload($request->user(), $request->only('title', 'consent', 'request_key'), $request->file('file'));

        return back()->with('success', 'نسخهٔ آمادهٔ عکس خصوصی ثبت شد؛ برای نمایش عمومی آن را در پروفایل انتخاب و همان نسخه را برای بررسی ارسال کنید.');
    }

    public function withdraw(Request $request, CoachProfilePhoto $photo, CoachProfilePhotos $photos): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'nullable|string|max:2000']);
        $photos->withdraw($request->user(), $photo, $data['revision'], $data['reason'] ?? null);

        return back()->with('success', 'دریافت تازهٔ عکس متوقف شد؛ نسخهٔ آماده، رضایت و سابقه حفظ شدند.');
    }

    private function response(array $prepared, bool $private): StreamedResponse
    {
        $response = response()->stream(function () use ($prepared): void {
            try {
                fpassthru($prepared['stream']);
            } finally {
                fclose($prepared['stream']);
            }
        }, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) $prepared['size'], 'Content-Disposition' => 'inline; filename="'.$prepared['name'].'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'no-referrer']);
        if ($private) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    public function preview(Request $request, string $key, CoachProfilePhotos $photos): StreamedResponse
    {
        return $this->response($photos->privateFile($request->user(), $key), true);
    }

    public function publicFile(string $slug, string $key, string $sha, CoachProfilePhotos $photos): StreamedResponse
    {
        return $this->response($photos->publicFile($slug, $key, $sha), false);
    }
}
