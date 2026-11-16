<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuditEventController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate([
            'event_id' => 'nullable|integer|min:1',
            'actor_id' => 'nullable|integer|min:1',
            'subject_id' => 'nullable|integer|min:1',
            'action' => 'nullable|string|max:255',
            'subject_type' => 'nullable|string|max:255',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:1000000',
        ]);
        $filters = array_fill_keys(['event_id', 'actor_id', 'subject_id', 'action', 'subject_type', 'from', 'to'], '');
        foreach ($filters as $key => $unused) {
            if (isset($data[$key])) {
                $filters[$key] = in_array($key, ['event_id', 'actor_id', 'subject_id'], true) ? (int) $data[$key] : trim($data[$key]);
            }
        }
        if ($filters['from'] && $filters['to'] && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'پایان بازه باید هم‌زمان یا پس از آغاز آن باشد.']);
        }
        $query = AuditEvent::query();
        foreach (['event_id' => 'id', 'actor_id' => 'actor_id', 'subject_id' => 'subject_id', 'action' => 'action', 'subject_type' => 'subject_type'] as $filter => $column) {
            if ($filters[$filter] !== '') {
                $query->where($column, $filters[$filter]);
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $filter => $operator) {
            if ($filters[$filter] !== '') {
                $boundary = CarbonImmutable::createFromFormat('!Y-m-d', $filters[$filter], 'Asia/Tehran');
                if ($filter === 'to') {
                    $boundary = $boundary->addDay();
                }
                $query->where('created_at', $operator, $boundary->timezone(config('app.timezone')));
            }
        }
        $events = $query->with('actor:id,name')->orderByDesc('id')->paginate(25, ['id', 'actor_id', 'action', 'subject_type', 'subject_id', 'created_at'])->appends(array_filter($filters, fn ($value) => $value !== ''))->through(fn ($event) => [
            'id' => $event->id,
            'action' => $event->action,
            'actor_id' => $event->actor_id,
            'actor_name' => $event->actor?->name,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'created_at' => $event->created_at->toIso8601String(),
        ]);

        return Inertia::render('AuditEvents', [
            'filters' => $filters,
            'events' => $events,
            'actions' => AuditEvent::distinct()->orderBy('action')->limit(200)->pluck('action'),
            'subject_types' => AuditEvent::whereNotNull('subject_type')->distinct()->orderBy('subject_type')->limit(100)->pluck('subject_type'),
        ]);
    }
}
