<?php

namespace App\Console\Commands;

use App\Support\ProcessNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeProcessNumbersCommand extends Command
{
    protected $signature = 'process-numbers:normalize {--confirm : grava as alterações (sem isso é só prévia)}';

    protected $description = 'Formata no padrão CNJ os números de processo gravados só com 20 dígitos.';

    /** tabela => coluna */
    public const COLUMNS = [
        'writs' => 'process_number',
        'transactions' => 'link_process',
        'process_receipts' => 'process_ref',
        'broker_commissions' => 'process_ref',
        'broker_advances' => 'process_ref',
        'broker_process_expenses' => 'process_ref',
        'receivables' => 'process_reference',
    ];

    public function handle(): int
    {
        $changes = [];
        foreach (self::COLUMNS as $table => $column) {
            $rows = DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id')->get(['id', $column]);
            $fix = [];
            foreach ($rows as $row) {
                $new = ProcessNumber::format($row->$column);
                if ($new !== $row->$column && ProcessNumber::isCanonical($new)) {
                    $fix[] = ['id' => $row->id, 'old' => $row->$column, 'new' => $new];
                }
            }
            $this->line(sprintf('%s.%s: %d preenchidos, %d a formatar%s', $table, $column, $rows->count(), count($fix), $fix ? ' (ids '.implode(',', array_column($fix, 'id')).')' : ''));
            if ($fix) {
                $changes[$table] = ['column' => $column, 'rows' => $fix];
            }
        }
        if (! $this->option('confirm') || ! $changes) {
            $this->info($changes ? 'Prévia: nada foi gravado. Use --confirm para aplicar.' : 'Nada a formatar.');

            return self::SUCCESS;
        }
        $backup = storage_path('app/backups/process_numbers_'.now()->format('Ymd_His').'.json');
        @mkdir(dirname($backup), 0775, true);
        file_put_contents($backup, json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        DB::transaction(function () use ($changes) {
            foreach ($changes as $table => $change) {
                foreach ($change['rows'] as $row) {
                    DB::table($table)->where('id', $row['id'])->where($change['column'], $row['old'])->update([$change['column'] => $row['new']]);
                }
            }
        });
        $this->info('Aplicado. Backup: '.$backup);

        return self::SUCCESS;
    }
}
