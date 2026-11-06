<?php

namespace App\Domains\Investments\Services\Statements;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\BrokerStatementImport;
use App\Domains\Investments\Models\BrokerStatementRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Extratos de corretora → staging editável → confirmação explícita.
 * Confirmar cria só eventos de provento/aluguel em asset_income_events (sem Financeiro).
 * Compras/vendas/transferências ficam "pendente_manual" (registrar em Movimentações), nunca automáticas.
 */
class BrokerStatementImportService
{
    public const DIR = 'imports/broker-statements';

    public function __construct(private TabularFileReader $reader, private BrokerStatementParser $parser, private PdfTextExtractor $pdf) {}

    public function stage(UploadedFile $file, string $broker, ?int $userId): BrokerStatementImport
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $kind = match ($ext) { 'csv', 'txt' => 'csv', 'xlsx' => 'xlsx', 'pdf' => 'pdf', default => throw new RuntimeException('Envie XLSX, CSV ou PDF.') };
        $path = $file->storeAs(self::DIR.'/'.Str::uuid(), 'original.'.$kind, 'local');
        $abs = Storage::disk('local')->path($path);
        $import = BrokerStatementImport::create([
            'broker_name' => mb_substr(trim($broker), 0, 80), 'file_name' => mb_substr($file->getClientOriginalName(), 0, 250), 'file_kind' => $kind,
            'file_path' => $path, 'file_hash' => hash_file('sha256', $abs), 'status' => 'draft', 'created_by' => $userId,
            'expires_at' => now()->addHours(BrokerStatementImport::TTL_HOURS),
        ]);
        if ($kind === 'pdf') {
            $r = $this->pdf->extract($abs);
            if ($r['status'] === 'text_extracted' && BbHomeBrokerPdfParser::detect($r['text'])) {
                $bb = app(BbHomeBrokerPdfParser::class);
                $parsed = $bb->parse($r['text']);
                $import->update(['text_status' => 'text_extracted', 'headers' => null, 'mapping' => ['layout' => 'bb_home_broker'], 'summary' => ['reconciliation' => $parsed['reconciliation']]]);
                $this->buildLayoutRows($import, $bb->toStagingRows($parsed), $parsed['reconciliation']);

                return $import->fresh();
            }
            if ($r['status'] === 'text_extracted' && BtgStatementPdfParser::detect($r['text'])) {
                $btg = app(BtgStatementPdfParser::class);
                $parsed = $btg->parse($r['text']);
                $import->update(['text_status' => 'text_extracted', 'headers' => null, 'mapping' => ['layout' => 'btg_conta_investimento'], 'summary' => ['reconciliation' => $parsed['reconciliation']]]);
                $this->buildLayoutRows($import, $btg->toStagingRows($parsed), $parsed['reconciliation']);

                return $import->fresh();
            }
            $import->update(['status' => 'needs_review', 'text_status' => $r['status'], 'summary' => ['pdf_chars' => mb_strlen($r['text']), 'message' => match ($r['status']) {
                'text_extracted' => 'PDF com texto, mas sem layout validado desta corretora: envie o XLSX/CSV original ou cadastre manualmente. Nada será importado do PDF automaticamente.',
                'no_text' => 'PDF sem texto (provavelmente escaneado). Precisa do arquivo original XLSX/CSV ou revisão manual.',
                'extractor_unavailable' => 'Extrator de texto de PDF não instalado no servidor. Envie o XLSX/CSV original.',
                default => 'Arquivo não é um PDF válido.',
            }]]);

            return $import;
        }
        $table = $this->reader->read($abs, $kind);
        $import->update(['headers' => $table['headers'], 'mapping' => $this->parser->suggestMapping($table['headers'])]);
        $this->buildRows($import, $table);

        return $import->fresh();
    }

    /** Reaplica o mapeamento (edição do usuário ou sugestão da IA aceita). Confere o hash do original. */
    public function remap(BrokerStatementImport $import, array $mapping): BrokerStatementImport
    {
        $this->assertOpen($import);
        $mapping = array_filter($mapping, fn ($col, $f) => isset(BrokerStatementParser::FIELDS[$f]) && in_array($col, $import->headers ?? [], true), ARRAY_FILTER_USE_BOTH);
        $import->update(['mapping' => $mapping]);
        $this->buildRows($import, $this->reader->read($this->verifiedPath($import), $import->file_kind));

        return $import->fresh();
    }

    /** Linhas já normalizadas por um parser de layout (ex.: BTG PDF). IR vinculado vira "linked". */
    public function buildLayoutRows(BrokerStatementImport $import, array $rows, array $reconciliation): void
    {
        DB::transaction(function () use ($import, $rows, $reconciliation) {
            $import->rows()->delete();
            $seen = [];
            $ids = [];
            foreach ($rows as $i => $n) {
                $base = 'stmt:'.sha1(mb_strtolower($import->broker_name).'|'.$n['date'].'|'.$n['ticker'].'|'.$n['kind'].'|'.$n['source_type_label'].'|'.$n['net_amount']);
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $link = $n['link_to'];
                unset($n['link_to']);
                $row = BrokerStatementRow::create($n + ['import_id' => $import->id, 'row_number' => $i + 1, 'source_key' => $base.':'.$seen[$base], 'status' => 'pending']);
                $ids[$i] = $row->id;
                if ($link !== null) {
                    $row->update(['status' => 'linked', 'external_id' => 'linha '.($link + 1)]);
                }
            }
            $import->update(['summary' => ['reconciliation' => $reconciliation]]);
            $this->classify($import);
        });
    }

    public function buildRows(BrokerStatementImport $import, array $table): void
    {
        DB::transaction(function () use ($import, $table) {
            $import->rows()->delete();
            $seen = [];
            foreach ($table['rows'] as $i => $raw) {
                $n = $this->parser->normalize($table['headers'], $raw, $import->mapping ?? []);
                $base = 'stmt:'.sha1(mb_strtolower($import->broker_name).'|'.$n['date'].'|'.$n['ticker'].'|'.$n['kind'].'|'.$n['quantity'].'|'.$n['net_amount'].'|'.$n['external_id']);
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                BrokerStatementRow::create($n + ['import_id' => $import->id, 'row_number' => $i + 1, 'raw' => $raw, 'source_key' => $base.':'.$seen[$base], 'status' => 'pending']);
            }
            $this->classify($import);
        });
    }

    /** Define o status de cada linha (pronto, erro, duplicado exato, candidato a duplicado, pendente manual). */
    public function classify(BrokerStatementImport $import): void
    {
        foreach ($import->rows()->get() as $row) {
            if (in_array($row->status, ['excluded', 'imported', 'linked', 'held_review', 'duplicate_not_imported'], true)) {
                continue;
            }
            $msgs = collect($row->messages ?? [])->reject(fn ($m) => str_starts_with($m, 'Possível duplicado'))->values()->all();
            $status = 'ready';
            $dup = null;
            if (! in_array($row->kind, BrokerStatementRow::INCOME_KINDS, true)) {
                $status = in_array($row->kind, BrokerStatementRow::MANUAL_KINDS, true) ? 'pending_manual' : 'error';
            } elseif (! $row->date || $row->net_amount === null || ! $row->ticker) {
                $status = 'error';
            } elseif (AssetIncomeEvent::where('source_key', $row->source_key)->exists()) {
                $status = 'duplicate';
            } else {
                $dup = AssetIncomeEvent::where('ticker', $row->ticker)
                    ->whereBetween('payment_date', [$row->date->copy()->subDays(3)->toDateString(), $row->date->copy()->addDays(3)->toDateString()])
                    ->whereRaw('ABS(net_amount - ?) <= 0.01', [(float) $row->net_amount])->orderBy('id')->first();
                if ($dup && ! $row->force_import) {
                    $status = 'duplicate_candidate';
                    $msgs[] = 'Possível duplicado de evento já importado (#'.$dup->id.', '.$dup->source.', '.$dup->payment_date->format('d/m/Y').'). Confirme manualmente se é outro evento.';
                }
            }
            $row->update(['status' => $status, 'duplicate_of_event_id' => $dup?->id, 'messages' => $msgs]);
        }
        $import->update(['summary' => $this->summary($import) + array_intersect_key($import->summary ?? [], ['reconciliation' => 1])]);
    }

    public function updateRow(BrokerStatementRow $row, array $data): void
    {
        $this->assertOpen($row->import);
        $allowed = array_intersect_key($data, array_flip(['kind', 'ticker', 'date', 'net_amount', 'force_import']));
        if (isset($allowed['kind']) && ! isset(BrokerStatementRow::KINDS[$allowed['kind']])) {
            unset($allowed['kind']);
        }
        if (array_key_exists('excluded', $data)) {
            $allowed['status'] = $data['excluded'] ? 'excluded' : 'pending';
        }
        $row->update($allowed);
        $this->classify($row->import);
    }

    /** @return array{imported: int, skipped: int, pending_manual: int} */
    public function confirm(BrokerStatementImport $import, int $userId): array
    {
        $this->assertOpen($import);
        $this->verifiedPath($import);
        $this->classify($import);
        if (isset($import->summary['reconciliation']) && ! $import->summary['reconciliation']['ok']) {
            throw new RuntimeException('O extrato não reconcilia (saldo/totais): revise antes de importar.');
        }
        if ($import->rows()->where('status', 'error')->exists()) {
            throw new RuntimeException('Há linhas com erro: corrija ou exclua antes de confirmar.');
        }

        return DB::transaction(function () use ($import, $userId) {
            $assets = Asset::pluck('id', 'ticker');
            $imported = 0;
            foreach ($import->rows()->where('status', 'ready')->get() as $row) {
                $event = AssetIncomeEvent::create([
                    'asset_id' => $assets[$row->ticker] ?? null, 'ticker' => $row->ticker, 'payment_date' => $row->date, 'type' => $row->kind,
                    'source_type_label' => $row->source_type_label, 'quantity' => $row->quantity, 'gross_amount' => $row->gross_amount,
                    'withholding_tax' => $row->withholding_tax, 'net_amount' => $row->net_amount,
                    'is_lending' => in_array($row->kind, ['lending_fee', 'lending_reimbursement'], true), 'lending_fee_rate' => $row->lending_fee_rate,
                    'lending_contract_ref' => $row->external_id, 'broker_name' => $import->broker_name, 'source' => 'broker_statement',
                    'source_file' => mb_substr($import->file_name, 0, 250), 'source_key' => $row->source_key,
                ]);
                $row->update(['status' => 'imported', 'income_event_id' => $event->id]);
                $imported++;
            }
            $summary = $this->summary($import) + array_intersect_key($import->summary ?? [], ['reconciliation' => 1]);
            $this->deleteFile($import);
            $import->update(['status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => now(), 'summary' => $summary]);

            return ['imported' => $imported, 'skipped' => $summary['by_status']['duplicate'] ?? 0, 'pending_manual' => $summary['by_status']['pending_manual'] ?? 0];
        });
    }

    /**
     * Antes de confirmar: segura para revisão (não importa) linhas com ticker inferido ou inexistente em Ativos.
     * @return array{inferred: int, unknown_asset: int}
     */
    public function holdUncertain(BrokerStatementImport $import): array
    {
        $this->assertOpen($import);
        $tickers = Asset::pluck('ticker')->map(fn ($t) => strtoupper($t))->flip();
        $n = ['inferred' => 0, 'unknown_asset' => 0];
        foreach ($import->rows()->where('status', 'ready')->get() as $row) {
            $msgs = $row->messages ?? [];
            $inferred = (bool) array_filter($msgs, fn ($m) => str_contains($m, 'inferido'));
            $unknown = ! isset($tickers[strtoupper((string) $row->ticker)]);
            if ($inferred || $unknown) {
                $msgs[] = $inferred ? 'Retido: ticker inferido, confirmar antes de importar.' : 'Retido: ticker não cadastrado em Ativos.';
                $row->update(['status' => 'held_review', 'messages' => $msgs]);
                $n[$inferred ? 'inferred' : 'unknown_asset']++;
            }
        }
        foreach ($import->rows()->where('status', 'error')->get() as $row) {
            $row->update(['status' => 'held_review', 'messages' => array_merge($row->messages ?? [], ['Retido para revisão (sem ticker/dados).'])]);
        }
        $import->update(['summary' => $this->summary($import) + array_intersect_key($import->summary ?? [], ['reconciliation' => 1])]);

        return $n;
    }

    /** Após confirmar: candidatos a duplicado ficam registrados como não importados (B3 já tem o evento). */
    public function markDuplicatesNotImported(BrokerStatementImport $import): int
    {
        return $import->rows()->where('status', 'duplicate_candidate')->update(['status' => 'duplicate_not_imported']);
    }

    public function discard(BrokerStatementImport $import): void
    {
        $this->deleteFile($import);
        $import->rows()->delete();
        $import->update(['status' => 'discarded']);
    }

    /** Apaga originais e staging vencidos (TTL) e arquivos de lotes já fechados. */
    public function cleanup(): int
    {
        $n = 0;
        foreach (BrokerStatementImport::whereIn('status', ['draft', 'needs_review'])->where('expires_at', '<', now())->get() as $imp) {
            $this->deleteFile($imp);
            $imp->rows()->delete();
            $imp->update(['status' => 'expired']);
            $n++;
        }
        foreach (BrokerStatementImport::whereIn('status', ['confirmed', 'discarded', 'expired'])->whereNotNull('file_path')->get() as $imp) {
            $this->deleteFile($imp);
            $n++;
        }

        return $n;
    }

    public function summary(BrokerStatementImport $import): array
    {
        $rows = $import->rows()->get();

        return [
            'rows' => $rows->count(),
            'by_status' => $rows->countBy('status')->all(),
            'by_kind' => $rows->groupBy('kind')->map(fn ($g) => ['count' => $g->count(), 'net' => round((float) $g->sum('net_amount'), 2)])->all(),
            'ready_net' => round((float) $rows->where('status', 'ready')->sum('net_amount'), 2),
        ];
    }

    private function assertOpen(BrokerStatementImport $import): void
    {
        if (! $import->isOpen()) {
            throw new RuntimeException('Lote fechado ou expirado: envie o arquivo novamente.');
        }
    }

    private function verifiedPath(BrokerStatementImport $import): string
    {
        if (! $import->file_path || ! Storage::disk('local')->exists($import->file_path)) {
            throw new RuntimeException('Arquivo original não está mais disponível: envie novamente.');
        }
        $abs = Storage::disk('local')->path($import->file_path);
        if (hash_file('sha256', $abs) !== $import->file_hash) {
            throw new RuntimeException('O arquivo mudou depois da prévia: a prévia foi invalidada, envie novamente.');
        }

        return $abs;
    }

    private function deleteFile(BrokerStatementImport $import): void
    {
        if ($import->file_path) {
            Storage::disk('local')->deleteDirectory(dirname($import->file_path));
            $import->update(['file_path' => null]);
        }
    }
}
