<?php

namespace App\Http\Controllers;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Services\CashflowReportService;
use App\Domains\Dashboard\Support\ReportPeriod;
use App\Support\BusinessDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Exportação autenticada do Fluxo de caixa (CSV UTF-8 com BOM e PDF), com os mesmos filtros da tela. */
class CashflowExportController extends Controller
{
    public function __invoke(Request $request, string $format, CashflowReportService $service): Response
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);
        $v = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'conta' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'categoria' => ['nullable', 'integer', 'exists:categories,id'],
            'pessoa' => ['nullable', 'string', 'max:100'],
            'projecao' => ['nullable', 'in:3,6,12'],
        ]);
        $period = ! empty($v['de']) && ! empty($v['ate'])
            ? ReportPeriod::fromInput('custom', $v['de'], $v['ate'])
            : ReportPeriod::month($v['month'] ?? BusinessDate::now()->format('Y-m'));
        $filters = ['account_id' => $v['conta'] ?? null, 'category_id' => $v['categoria'] ?? null, 'person' => $v['pessoa'] ?? null];
        $report = $service->build($period, $filters);
        $projection = $service->projection((int) ($v['projecao'] ?? 3), $filters);
        $people = $service->personOptions();
        $filterLabels = [
            'Conta' => $filters['account_id'] ? BankAccount::find($filters['account_id'])?->name : 'Todas',
            'Categoria' => $filters['category_id'] ? Category::find($filters['category_id'])?->name : 'Todas',
            'Pessoa' => $report['filters']['person'] ? ($people[$report['filters']['person']] ?? $report['filters']['person']) : 'Todas',
            'Regime' => $report['regime'] === 'accrual' ? 'Competência' : 'Caixa',
        ];
        $generatedAt = BusinessDate::now();
        $name = 'fluxo-de-caixa-'.$period->start->format('Y-m-d').'-a-'.$period->end->format('Y-m-d');

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.banking.cashflow', compact('period', 'report', 'projection', 'filterLabels', 'generatedAt'))
                ->setPaper('a4', 'portrait')->download($name.'.pdf');
        }

        $n = fn ($v) => number_format((float) $v, 2, ',', '');
        $lines = [];
        $lines[] = ['Fluxo de caixa', $period->label()];
        $lines[] = ['Gerado em (horário de Fortaleza)', $generatedAt->format('d/m/Y H:i')];
        foreach ($filterLabels as $k => $l) {
            $lines[] = [$k, (string) $l];
        }
        $lines[] = [];
        $lines[] = ['Resumo', 'Atual', 'Período anterior', 'Variação', 'Variação %'];
        foreach (['income' => 'Receitas próprias', 'expense' => 'Despesas próprias', 'result' => 'Resultado próprio'] as $k => $l) {
            $c = $report['comparison'][$k];
            $lines[] = [$l, $n($c['current']), $n($c['previous']), $n($c['diff']), $c['pct'] === null ? '' : number_format($c['pct'], 1, ',', '')];
        }
        $lines[] = ['Saldo nas contas', $n($report['cash']['accounts_balance'])];
        $lines[] = ['Dinheiro de terceiros', $n($report['cash']['third_party_balance'])];
        $lines[] = ['Saldo próprio em '.\Illuminate\Support\Carbon::parse($report['position_date'])->format('d/m/Y'), $n($report['cash']['own_balance'])];
        $lines[] = ['Entradas previstas', $n($report['forecast']['income'])];
        $lines[] = ['Saídas previstas', $n($report['forecast']['expense'])];
        $lines[] = [];
        $lines[] = ['Categoria', 'Receita própria', 'Despesa própria', 'Outras entradas', 'Outras saídas', 'Resultado próprio'];
        foreach ($report['realized']['by_category'] as $cat => $row) {
            $lines[] = [$cat, $n($row['own_income']), $n($row['own_expense']), $n($row['third_party_in']), $n($row['third_party_out']), $n($row['own_income'] - $row['own_expense'])];
        }
        $lines[] = [];
        $lines[] = ['Conta', 'Entradas', 'Saídas', 'Saldo do período'];
        foreach ($report['realized']['by_account'] as $acc => $row) {
            $lines[] = [$acc, $n($row['in']), $n($row['out']), $n($row['in'] - $row['out'])];
        }
        $lines[] = [];
        $lines[] = ['Previsto: data', 'Origem', 'Descrição', 'Entrada', 'Saída'];
        foreach ($report['forecast']['items'] as $i) {
            $lines[] = [\Illuminate\Support\Carbon::parse($i['date'])->format('d/m/Y'), $i['source'], $i['description'], $i['direction'] === 'in' ? $n($i['amount']) : '', $i['direction'] === 'out' ? $n($i['amount']) : ''];
        }
        $lines[] = [];
        $lines[] = ['Projeção: mês', 'Entradas previstas', 'Saídas previstas', 'Líquido', 'Saldo projetado'];
        foreach ($projection['rows'] as $row) {
            $lines[] = [$row['label'], $n($row['income']), $n($row['expense']), $n($row['net']), $n($row['balance'])];
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($lines as $line) {
            fputcsv($fh, $line, ';', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.csv"',
        ]);
    }
}
