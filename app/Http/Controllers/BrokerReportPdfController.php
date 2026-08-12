<?php

namespace App\Http\Controllers;

use App\Domains\Brokers\Services\BrokerReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class BrokerReportPdfController extends Controller
{
    public function __invoke(Request $request, BrokerReportService $reportService): Response
    {
        $currentYear = now()->year;
        $validated = $request->validate([
            'month' => ['nullable', Rule::in(['all', ...range(1, 12)])],
            'year' => ['nullable', 'integer', 'min:'.($currentYear - 3), 'max:'.($currentYear + 2)],
            'broker_id' => ['nullable', 'integer', 'exists:brokers,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $month = (string) ($validated['month'] ?? now()->month);
        $year = (int) ($validated['year'] ?? $currentYear);
        $brokerId = isset($validated['broker_id']) ? (int) $validated['broker_id'] : null;
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        $report = $reportService->generate($month, $year, $brokerId, $startDate, $endDate);
        $filename = 'relatorio-corretores-'.$this->filenamePeriod($report['rangeStart'], $report['rangeEnd']).'.pdf';

        return Pdf::loadView('pdf.brokers.report', [
            ...$report,
            'generatedAt' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    private function filenamePeriod(?string $startDate, ?string $endDate): string
    {
        if ($startDate && $endDate) {
            return $startDate.'-a-'.$endDate;
        }

        return $startDate ?: ($endDate ?: now()->format('Y-m-d'));
    }
}
