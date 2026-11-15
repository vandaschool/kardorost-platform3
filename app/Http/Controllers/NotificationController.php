<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications', ['notifications' => $request->user()->notifications()->latest()->paginate(20), 'sms_reminders_enabled' => $request->user()->sms_reminders_enabled]);
    }

    public function preferences(Request $request): RedirectResponse
    {
        $data = $request->validate(['sms_reminders_enabled' => 'required|boolean']);
        $request->user()->forceFill($data)->save();
        AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'notifications.preferences_changed', 'subject_type' => 'user', 'subject_id' => $request->user()->id, 'metadata' => $data]);

        return back()->with('success', 'ترجیح پیامک یادآوری ذخیره شد.');
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return back();
    }
}
