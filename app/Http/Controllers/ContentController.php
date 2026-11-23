<?php

namespace App\Http\Controllers;

use App\Models\ContentPage;
use App\Models\ContentRedirect;
use App\Models\ContentSchedule;
use App\Services\ContentPublishing;
use App\Services\ContentRedirects;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('AdminContent', ['pages' => ContentPage::with('currentPublication', 'pendingSchedule')->latest('id')->paginate(20)->through(fn ($page) => [...$page->only('id', 'slug', 'kind', 'current_version', 'available'), 'title' => $page->draft['title'], 'public_path' => $page->publicPath(), 'scheduled_for' => $page->pendingSchedule?->scheduled_for])]);
    }

    public function create(Request $request, ContentPublishing $publishing): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|string', 'slug' => 'required|string|max:80', 'definition' => 'required|array']);
        $page = $publishing->create($request->user(), $data['kind'], $data['slug'], $data['definition']);

        return redirect()->route('admin.content.edit', $page);
    }

    public function edit(ContentPage $page): Response
    {
        return Inertia::render('ContentEditor', ['page' => [...$page->only('id', 'slug', 'kind', 'revision', 'current_version', 'available', 'draft'), 'public_path' => $page->publicPath()], 'pending_schedule' => $page->pendingSchedule?->only('id', 'scheduled_for'), 'schedules' => $page->schedules()->with('author:id,name', 'publication:id,number')->latest('id')->paginate(10, ['*'], 'schedules_page')->withQueryString()->through(fn ($row) => [...$row->only('id', 'source_revision', 'definition', 'scheduled_for', 'status', 'reason', 'closed_at'), 'author_name' => $row->author->name, 'publication_number' => $row->publication?->number]), 'redirects' => $page->redirects()->orderBy('id')->limit(25)->get()->map(fn ($row) => [...$row->only('id', 'from_slug', 'enabled', 'revision'), 'source_path' => $row->sourcePath()]), 'publications' => $page->publications()->latest('number')->paginate(10)->through(fn ($publication) => $publication->only('id', 'number', 'definition', 'published_at'))]);
    }

    public function createRedirect(Request $request, ContentPage $page, ContentRedirects $service): RedirectResponse
    {
        $data = $request->validate(['from_slug' => 'required|string|max:80']);
        $service->create($request->user(), $page, $data['from_slug']);

        return back()->with('success', 'انتقال موقت نشانی ثبت شد.');
    }

    public function changeRedirect(Request $request, ContentPage $page, ContentRedirect $redirect, ContentRedirects $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'enabled' => 'required|boolean']);
        $service->change($request->user(), $page, $redirect, $data['revision'], $data['enabled']);

        return back()->with('success', 'وضعیت انتقال نشانی تغییر کرد.');
    }

    public function draft(Request $request, ContentPage $page, ContentPublishing $publishing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'definition' => 'required|array']);
        $publishing->draft($request->user(), $page, $data['revision'], $data['definition']);

        return back()->with('success', 'پیش‌نویس ذخیره شد؛ سایت عمومی تغییر نکرد.');
    }

    public function publish(Request $request, ContentPage $page, ContentPublishing $publishing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $publishing->publish($request->user(), $page, $data['revision']);

        return back()->with('success', 'نسخه ذخیره‌شده روی سایت عمومی منتشر شد.');
    }

    public function availability(Request $request, ContentPage $page, ContentPublishing $publishing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'available' => 'required|boolean']);
        $publishing->availability($request->user(), $page, $data['revision'], $data['available']);

        return back()->with('success', 'وضعیت نمایش عمومی تغییر کرد.');
    }

    public function schedule(Request $request, ContentPage $page, ContentPublishing $publishing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i', 'reviewed' => 'required|accepted']);
        $at = CarbonImmutable::createFromFormat('!Y-m-d H:i', $data['date'].' '.$data['time'], 'Asia/Tehran');
        $publishing->schedule($request->user(), $page, $data['revision'], $at, true);

        return back()->with('success', 'همین نسخه برای انتشار در زمان انتخاب‌شده ثبت شد.');
    }

    public function cancelSchedule(Request $request, ContentPage $page, ContentSchedule $schedule, ContentPublishing $publishing): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $publishing->cancelSchedule($request->user(), $page, $data['revision'], $schedule);

        return back()->with('success', 'برنامه انتشار لغو شد؛ نسخه عمومی فعلی تغییر نکرد.');
    }
}
