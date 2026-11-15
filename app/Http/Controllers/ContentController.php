<?php

namespace App\Http\Controllers;

use App\Models\ContentPage;
use App\Services\ContentPublishing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('AdminContent', ['pages' => ContentPage::with('currentPublication')->latest('id')->paginate(20)->through(fn ($page) => [...$page->only('id', 'slug', 'kind', 'current_version', 'available'), 'title' => $page->draft['title'], 'public_path' => $page->publicPath()])]);
    }

    public function create(Request $request, ContentPublishing $publishing): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|string', 'slug' => 'required|string|max:80', 'definition' => 'required|array']);
        $page = $publishing->create($request->user(), $data['kind'], $data['slug'], $data['definition']);

        return redirect()->route('admin.content.edit', $page);
    }

    public function edit(ContentPage $page): Response
    {
        return Inertia::render('ContentEditor', ['page' => [...$page->only('id', 'slug', 'kind', 'revision', 'current_version', 'available', 'draft'), 'public_path' => $page->publicPath()], 'publications' => $page->publications()->latest('number')->paginate(10)->through(fn ($publication) => $publication->only('id', 'number', 'definition', 'published_at'))]);
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
}
