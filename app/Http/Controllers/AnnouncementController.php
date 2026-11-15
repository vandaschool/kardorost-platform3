<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\Announcements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    private function page(Announcements $service, ?Announcement $announcement = null): Response
    {
        $counts = $announcement?->recipients()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($count) => (int) $count)->all();
        $recipients = $announcement?->recipients()->with('user:id,name')->latest('id')->paginate(20, ['id', 'user_id', 'status', 'failure_code', 'finished_at'], 'recipients_page')->through(fn ($row) => ['id' => $row->id, 'user_id' => $row->user_id, 'name' => $row->user->name, 'status' => $row->status, 'failure_code' => $row->failure_code, 'finished_at' => $row->finished_at]);

        return Inertia::render('Announcements', ['announcements' => Announcement::latest('id')->paginate(15)->through(fn ($row) => $row->only('id', 'title', 'status', 'recipient_count', 'published_at', 'created_at')), 'selected' => $announcement?->only('id', 'title', 'message', 'roles', 'status', 'revision', 'recipient_count', 'published_at', 'finished_at', 'stop_reason'), 'counts' => $counts, 'recipients' => $recipients, 'estimated_audience' => $announcement?->status === 'draft' ? $service->audience($announcement->roles)->count() : null]);
    }

    public function index(Announcements $service): Response
    {
        return $this->page($service);
    }

    public function show(Announcement $announcement, Announcements $service): Response
    {
        return $this->page($service, $announcement);
    }

    public function save(Request $request, Announcements $service, ?Announcement $announcement = null): RedirectResponse
    {
        $data = $request->validate(['definition' => 'required|array', 'revision' => 'required|integer|min:1']);
        $announcement = $service->save($request->user(), $data['definition'], $announcement, $data['revision']);

        return redirect()->route('admin.announcements.show', $announcement)->with('success', 'پیش‌نویس اطلاعیه ذخیره شد؛ هنوز اعلانی ارسال نشده است.');
    }

    public function publish(Request $request, Announcement $announcement, Announcements $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reviewed' => 'required|accepted']);
        $service->publish($request->user(), $announcement, $data['revision']);
        $service->dispatchPending();

        return back()->with('success', 'مخاطبان نهایی ثبت شدند؛ ارسال اعلان داخل پنل در صف قرار گرفت.');
    }

    public function stop(Request $request, Announcement $announcement, Announcements $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000']);
        $service->stop($request->user(), $announcement, $data['revision'], $data['reason']);

        return back()->with('success', 'ارسال باقی‌مانده متوقف شد؛ اعلان‌های قبلاً رسیده در سوابق می‌مانند.');
    }

    public function retry(Request $request, Announcement $announcement, Announcements $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $service->retry($request->user(), $announcement, $data['revision']);
        $service->dispatchPending();

        return back()->with('success', 'ارسال موارد باقی‌مانده دوباره در صف قرار گرفت.');
    }
}
