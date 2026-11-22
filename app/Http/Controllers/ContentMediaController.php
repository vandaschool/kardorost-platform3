<?php

namespace App\Http\Controllers;

use App\Models\ContentMedia;
use App\Services\ContentMediaLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ContentMediaController extends Controller
{
    public function index(Request $request, ContentMediaLibrary $library): Response
    {
        $library->admin($request->user());
        $files = ContentMedia::latest('id')->paginate(20)->through(function (ContentMedia $media) use ($library): array {
            $m = $library->metadata($media);

            return [...$media->only('id', 'public_key', 'revision', 'published_at', 'withdrawn_at', 'created_at'), 'metadata' => $m ? array_intersect_key($m, array_flip(['title', 'file_name', 'mime', 'size', 'width', 'height', 'sha256'])) : null];
        });

        return Inertia::render('ContentMedia', ['files' => $files, 'rights' => ContentMediaLibrary::RIGHTS]);
    }

    public function options(Request $request, ContentMediaLibrary $library): JsonResponse
    {
        $library->admin($request->user());
        $data = $request->validate(['q' => 'nullable|string|max:80']);
        $query = mb_strtolower(trim($data['q'] ?? ''));
        $items = [];
        foreach (ContentMedia::whereNotNull('published_at')->whereNull('withdrawn_at')->latest('id')->cursor() as $media) {
            $m = $library->metadata($media);
            if ($m && ($query === '' || str_contains(mb_strtolower($m['title']), $query))) {
                $items[] = ['key' => $media->public_key, ...array_intersect_key($m, array_flip(['title', 'width', 'height', 'sha256']))];
                if (count($items) >= 20) {
                    break;
                }
            }
        }

        return response()->json(['items' => $items])->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, ContentMediaLibrary $library): RedirectResponse
    {
        $request->validate(['file' => 'required|file']);
        $library->upload($request->user(), $request->only('title', 'rights_confirmed', 'request_key'), $request->file('file'));

        return back()->with('success', 'تصویر خصوصی ثبت شد؛ برای استفاده در سایت آن را جداگانه منتشر کنید.');
    }

    public function publish(Request $request, ContentMedia $media, ContentMediaLibrary $library): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reviewed' => 'required|accepted']);
        $library->decide($request->user(), $media, $data['revision'], true, (bool) $data['reviewed']);

        return back()->with('success', 'تصویر عمومی شد و در ویرایشگر محتوا قابل انتخاب است.');
    }

    public function withdraw(Request $request, ContentMedia $media, ContentMediaLibrary $library): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $library->decide($request->user(), $media, $data['revision'], false, false);

        return back()->with('success', 'دریافت تازهٔ تصویر متوقف شد؛ فایل و سابقه حفظ شدند.');
    }

    private function response(array $prepared, bool $private): StreamedResponse
    {
        return response()->stream(function () use ($prepared): void {
            try {
                fpassthru($prepared['stream']);
            } finally {
                fclose($prepared['stream']);
            }
        }, 200, ['Content-Type' => $prepared['mime'], 'Content-Disposition' => 'inline; filename="'.$prepared['name'].'"', 'Cache-Control' => $private ? 'private, no-store' : 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Referrer-Policy' => 'no-referrer']);
    }

    public function preview(Request $request, string $key, ContentMediaLibrary $library): StreamedResponse
    {
        $prepared = DB::transaction(function () use ($request, $key, $library): array {
            $library->admin($request->user(), true);
            $media = ContentMedia::where('public_key', $key)->sharedLock()->firstOrFail();
            abort_if($media->withdrawn_at, 404);
            try {
                return $library->stream($media);
            } catch (Throwable) {
                abort(404);
            }
        });

        $response = $this->response($prepared, true);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function publicFile(string $key, string $sha, ContentMediaLibrary $library): StreamedResponse
    {
        $prepared = DB::transaction(function () use ($key, $sha, $library): array {
            $media = ContentMedia::where('public_key', $key)->sharedLock()->firstOrFail();
            $m = $library->metadata($media);
            abort_unless($m && $media->published_at && ! $media->withdrawn_at && hash_equals($m['sha256'], $sha), 404);
            try {
                return $library->stream($media);
            } catch (Throwable) {
                abort(404);
            }
        });

        return $this->response($prepared, false);
    }
}
