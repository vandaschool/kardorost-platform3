<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportController extends Controller
{
    private function role(Request $request): string
    {
        $selected = $request->session()->get('panel_role');

        return $selected && $request->user()->hasRole($selected) ? $selected : ($request->user()->hasRole('admin') ? 'admin' : ($request->user()->hasRole('coach') ? 'coach' : ($request->user()->hasRole('student') ? 'student' : 'org_admin')));
    }

    private function messageRules(): array
    {
        return ['body' => 'nullable|required_without:file|string|max:12000', 'file' => 'nullable|required_without:body|file|mimes:pdf,jpg,jpeg,png,docx,txt|max:5120', 'request_key' => 'required|uuid'];
    }

    public function index(Request $request, SupportDesk $desk): Response
    {
        $filters = $request->validate(['view' => 'nullable|in:own,admin,coach', 'status' => 'nullable|in:waiting_staff,waiting_requester,resolved,closed', 'category' => 'nullable|in:technical,finance,academic,organization,other', 'priority' => 'nullable|in:normal,urgent', 'assigned' => 'nullable|in:mine,unassigned']);
        $view = $filters['view'] ?? 'own';
        $tickets = $desk->scope(SupportTicket::query(), $request->user(), $view)->with(['cohort.course:id,title', 'owner:id,name'])->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))->when($view === 'admin' && isset($filters['assigned']), fn ($q) => $q->when($filters['assigned'] === 'mine', fn ($q) => $q->where('assigned_to', $request->user()->id), fn ($q) => $q->whereNull('assigned_to')))->orderByDesc('last_message_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($ticket) => [...$ticket->only('id', 'subject', 'status', 'priority', 'category', 'last_message_at'), 'owner_name' => $view === 'own' ? null : $ticket->owner->name, 'cohort_title' => $ticket->cohort ? $ticket->cohort->course->title.' · '.$ticket->cohort->title : null]);
        $enrollments = Enrollment::with('cohort:id,title', 'course:id,title')->where('user_id', $request->user()->id)->where('status', 'active')->when(! $request->user()->hasRole('student'), fn ($q) => $q->whereRaw('1=0'))->get()->map(fn ($enrollment) => ['id' => $enrollment->cohort_id, 'title' => $enrollment->course->title.' · '.$enrollment->cohort->title]);

        return Inertia::render('Support', ['tickets' => $tickets, 'filters' => [...$filters, 'view' => $view], 'enrollments' => $enrollments, 'role' => $this->role($request), 'admin' => $request->user()->hasRole('admin'), 'coach' => $request->user()->hasRole('coach')]);
    }

    public function create(Request $request, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate([...$this->messageRules(), 'subject' => 'required|string|max:160', 'category' => 'required|in:technical,finance,academic,organization,other', 'priority' => 'required|in:normal,urgent', 'cohort_id' => 'nullable|prohibited_unless:category,academic|integer|exists:cohorts,id']);
        $ticket = $desk->create($request->user(), $data, $request->file('file'));

        return redirect()->route('support.show', $ticket)->with('success', 'درخواست خصوصی ثبت شد.');
    }

    public function show(Request $request, SupportTicket $ticket, SupportDesk $desk): Response
    {
        $desk->authorize($request->user(), $ticket);
        $admin = $request->user()->hasRole('admin');
        $messages = $ticket->messages()->with('author:id,name')->when(! $admin, fn ($q) => $q->where('internal_note', false))->orderBy('id')->paginate(20)->through(fn ($message) => [...$message->only('id', 'author_role', 'internal_note', 'created_at', 'file_name', 'file_size'), 'body' => $message->body, 'author_name' => $message->author->name, 'file_url' => $message->file_path ? route('support.file', $message->id) : null]);
        $events = $ticket->events()->when(! $admin, fn ($q) => $q->where('internal', false))->latest('id')->paginate(15, ['action', 'from_status', 'to_status', 'reason', 'internal', 'created_at'], 'events_page');
        $own = $ticket->user_id === $request->user()->id;
        $activeEnrollment = ! $ticket->cohort_id || Enrollment::where('cohort_id', $ticket->cohort_id)->where('user_id', $ticket->user_id)->where('status', 'active')->exists();

        return Inertia::render('SupportThread', ['ticket' => [...$ticket->only('id', 'subject', 'category', 'priority', 'status', 'revision'), 'owner_name' => $ticket->owner->name, 'cohort_title' => $ticket->cohort ? $ticket->cohort->course->title.' · '.$ticket->cohort->title : null, ...($admin ? ['assigned_to' => $ticket->assigned_to] : [])], 'messages' => $messages, 'events' => $events, 'admin' => $admin, 'own' => $own, 'can_reply' => ! in_array($ticket->status, ['resolved', 'closed']) && (! $own || $activeEnrollment), 'can_change' => $admin || $own, 'admins' => $admin ? User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('key', 'admin'))->get(['id', 'name']) : [], 'role' => $this->role($request)]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportDesk $desk): RedirectResponse
    {
        $desk->authorize($request->user(), $ticket);
        $data = $request->validate([...$this->messageRules(), 'internal_note' => 'sometimes|boolean']);
        $desk->reply($request->user(), $ticket, $data, $request->file('file'));
        $query = $ticket->messages()->when(! $request->user()->hasRole('admin'), fn ($q) => $q->where('internal_note', false));

        return redirect()->route('support.show', ['ticket' => $ticket->id, 'page' => max(1, (int) ceil($query->count() / 20))])->with('success', 'پیام ثبت شد.');
    }

    public function change(Request $request, SupportTicket $ticket, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:resolve,close,reopen,assign', 'reason' => 'required|string|max:2000', 'assigned_to' => 'nullable|integer|exists:users,id']);
        $desk->change($request->user(), $ticket, $data);

        return back()->with('success', 'تغییر ثبت شد.');
    }

    public function file(Request $request, TicketMessage $message, SupportDesk $desk): StreamedResponse
    {
        $desk->authorize($request->user(), $message->ticket);
        abort_if($message->internal_note && ! $request->user()->hasRole('admin'), 403);
        abort_unless($message->file_path && Storage::disk('support')->exists($message->file_path), 404);

        return Storage::disk('support')->download($message->file_path, $message->file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'application/octet-stream']);
    }
}
