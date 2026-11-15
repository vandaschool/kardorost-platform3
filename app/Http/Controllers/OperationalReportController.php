<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Services\OperationalReports;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalReportController extends Controller
{
    private function audit(Request $request, string $action, array $filters): void
    {
        AuditEvent::create(['actor_id' => $request->user()->id, 'action' => $action, 'subject_type' => 'operational_report', 'metadata' => $filters]);
    }

    public function index(Request $request, OperationalReports $reports): Response
    {
        $filters = $reports->filters($request->only('from', 'to', 'dataset'));
        $this->audit($request, 'operational_report.viewed', $filters);
        $records = $reports->related($reports->query($filters), $filters['dataset'])->orderByDesc('id')->paginate(20)->appends($filters)->through(fn ($record) => $reports->record($record, $filters['dataset']));

        return Inertia::render('OperationalReport', ['filters' => $filters, 'summary' => $reports->summary($filters), 'records' => $records, 'export_url' => route('admin.reports.export', $filters), 'generated_at' => now()->toIso8601String()]);
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+@\-\t\r\n]/u', ltrim($value)) ? "'".$value : $value;
    }

    public function export(Request $request, OperationalReports $reports): StreamedResponse
    {
        $filters = $reports->filters($request->only('from', 'to', 'dataset'));
        $this->audit($request, 'operational_report.exported', $filters);

        return response()->streamDownload(function () use ($reports, $filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['شناسه رکورد', 'شناسه حساب', 'موضوع', 'شناسه مبنا', 'مبلغ سند (ریال)', 'وضعیت فعلی', 'شناسه تأییدشده', 'زمان رویداد به وقت تهران'], ',', '"', '');
            foreach ($reports->related($reports->query($filters), $filters['dataset'])->lazyById(200) as $record) {
                $row = $reports->record($record, $filters['dataset']);
                fputcsv($out, array_map(fn ($value) => $this->csvCell($value), array_values($row)), ',', '"', '');
            }
            fclose($out);
        }, 'kardorost-'.$filters['dataset'].'-'.$filters['from'].'-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
