<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OfxImportService
{
    /**
     * @return array{account: ?string, statement_type: string, transactions: array<int, array{fitid: string, original_fitid: ?string, date: string, amount: string, type: string, description: string, notes: ?string, card_kind: ?string}>}
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('O arquivo OFX deve ter no máximo 2 MB.');
        }

        $contents = $this->toUtf8($contents);
        $isCard = (bool) preg_match('/<CCSTMTRS\b/i', $contents);
        $isBbBank = ! $isCard && mb_strtolower($this->field($contents, 'ORG')) === 'banco do brasil';

        if (! preg_match('/<OFX\b[^>]*>/i', $contents)) {
            throw new InvalidArgumentException('O arquivo não contém uma estrutura OFX válida.');
        }

        $statementCount = preg_match_all('/<BANKTRANLIST\b/i', $contents);

        if ($statementCount !== 1 || ! preg_match('/<\/BANKTRANLIST\s*>/i', $contents)) {
            throw new InvalidArgumentException('Selecione um OFX com um único extrato de conta ou fatura de cartão.');
        }

        preg_match_all('~<STMTTRN\b[^>]*>(.*?)</STMTTRN\s*>~is', $contents, $matches);

        if (preg_match_all('/<STMTTRN\b/i', $contents) !== count($matches[1])) {
            throw new InvalidArgumentException('O OFX contém lançamentos incompletos.');
        }

        if ($matches[1] === []) {
            throw new InvalidArgumentException('Nenhum lançamento bancário foi encontrado no OFX.');
        }

        if (count($matches[1]) > 5000) {
            throw new InvalidArgumentException('O OFX deve conter no máximo 5.000 lançamentos.');
        }

        $transactions = [];
        $seen = [];

        foreach ($matches[1] as $index => $block) {
            $fitid = $this->field($block, 'FITID');
            $dateValue = $this->field($block, 'DTPOSTED');
            $amountValue = $this->field($block, 'TRNAMT');
            $name = $this->field($block, 'NAME');
            $memo = $this->field($block, 'MEMO');

            if ($isBbBank && $fitid === '' && mb_strtolower($name) === 'saldo anterior') {
                continue;
            }

            if ($fitid === '' || mb_strlen($fitid) > 255 || ! preg_match('/^\d{8}/', $dateValue)) {
                throw new InvalidArgumentException('O lançamento '.($index + 1).' não possui identificador ou data válida.');
            }

            $year = (int) substr($dateValue, 0, 4);
            $month = (int) substr($dateValue, 4, 2);
            $day = (int) substr($dateValue, 6, 2);

            if (! checkdate($month, $day, $year)) {
                throw new InvalidArgumentException('O lançamento '.($index + 1).' possui data inválida.');
            }

            $amountParts = $this->amountParts($amountValue);
            if ($amountParts === null) {
                throw new InvalidArgumentException('O lançamento '.($index + 1).' possui valor inválido.');
            }

            $amount = ltrim($amountParts[2], '0');
            $amount = ($amount === '' ? '0' : $amount).'.'.str_pad($amountParts[3] ?? '', 2, '0');

            if ($amount === '0.00') {
                throw new InvalidArgumentException('O lançamento '.($index + 1).' possui valor zerado.');
            }

            if ($name === '' && $memo === '') {
                throw new InvalidArgumentException('O lançamento '.($index + 1).' não possui descrição.');
            }

            $originalFitid = $isBbBank ? $fitid : null;
            if ($isBbBank) {
                // O BB reutiliza FITID (como o código do Rende Fácil) em movimentos distintos.
                $fitid = 'bb:'.hash('sha256', json_encode([
                    $fitid, substr($dateValue, 0, 8),
                    ($amountParts[1] ?? '') === '-' ? '-'.$amount : $amount,
                    $name, $memo,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }

            if (isset($seen[$fitid])) {
                // Alguns bancos (ex.: Caixa) repetem o FITID em lançamentos diferentes: gera um id estável por conteúdo.
                $n = 2;
                do {
                    $candidate = $fitid.':'.substr(hash('sha256', implode('|', [substr($dateValue, 0, 8), ($amountParts[1] ?? '').$amount, $name, $memo, $n])), 0, 16);
                    $n++;
                } while (isset($seen[$candidate]));
                $fitid = $candidate;
            }

            $seen[$fitid] = true;
            $description = $name !== '' ? $name : $memo;

            $transactions[] = [
                'fitid' => $fitid,
                'original_fitid' => $originalFitid,
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'amount' => $amount,
                'type' => ($amountParts[1] ?? '') === '-' ? 'expense' : 'income',
                'description' => mb_substr($description, 0, 200),
                'notes' => $memo !== '' && $memo !== $name ? $memo : null,
                'card_kind' => ! $isCard ? null : (
                    preg_match('/^(?:PGTO|PAGAMENTO)\b/iu', $description) ? 'payment' :
                    (($amountParts[1] ?? '') === '-' ? 'purchase' : 'refund')
                ),
            ];
        }

        return [
            'account' => $this->field($contents, 'ACCTID') ?: null,
            'statement_type' => $isCard ? 'card' : 'bank',
            'transactions' => $transactions,
        ];
    }

    /** @param array<int, array<string, string|null>> $transactions */
    public function existingFitids(BankAccount|CreditCard $account, array $transactions): array
    {
        $found = [];
        $legacy = [];
        $ids = array_unique(array_merge(array_column($transactions, 'fitid'), array_filter(array_column($transactions, 'original_fitid'), fn ($id) => $id !== null && $id !== '')));

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Transaction::withTrashed()
                ->where($account instanceof CreditCard ? 'credit_card_id' : 'bank_account_id', $account->id)
                ->whereIn('ofx_fitid', $chunk)
                ->get(['ofx_fitid', 'date', 'amount', 'type']) as $transaction) {
                $found[$transaction->ofx_fitid] = true;
                $legacy[implode('|', [$transaction->ofx_fitid, $transaction->date->format('Y-m-d'), $transaction->amount, $transaction->type])] = true;
            }
        }

        $result = [];
        foreach ($transactions as $row) {
            if (isset($found[$row['fitid']]) || (isset($row['original_fitid']) && $row['original_fitid'] !== '' && isset($legacy[implode('|', [$row['original_fitid'], $row['date'], $row['amount'], $row['type']])]))) {
                $result[$row['fitid']] = true;
            }
        }

        return $result;
    }

    /** @param array<int, array<string, string|int|null>> $transactions
     * @return array{imported: int, skipped: int}
     */
    public function import(BankAccount $account, array $transactions): array
    {
        return DB::transaction(function () use ($account, $transactions) {
            $existing = $this->existingFitids($account, $transactions);
            $imported = 0;

            foreach ($transactions as $row) {
                if (isset($existing[$row['fitid']])) {
                    continue;
                }

                Transaction::create([
                    'type' => $row['type'],
                    'date' => $row['date'],
                    'amount' => $row['amount'],
                    'description' => $row['description'],
                    'notes' => $row['notes'],
                    'status' => 'settled',
                    'category_id' => $row['category_id'] ?? null,
                    'bank_account_id' => $account->id,
                    'ofx_fitid' => $row['fitid'],
                ]);
                $imported++;
            }

            return ['imported' => $imported, 'skipped' => count($transactions) - $imported];
        });
    }

    /**
     * Linhas marcadas como Transferência na revisão: cria o par saída/entrada (mesmo formato do TransferService)
     * ou, se a contrapartida já está na outra conta (mesmo valor, ±2 dias, sentido oposto), vincula sem duplicar.
     *
     * @param  array<int, array{fitid: string, date: string, amount: string, signed: float, transfer_account_id: int, notes: ?string}>  $rows
     * @return array{created: int, linked: int, skipped: int}
     */
    public function importTransfers(BankAccount $account, array $rows): array
    {
        return DB::transaction(function () use ($account, $rows) {
            $existing = $this->existingFitids($account, $rows);
            $created = $linked = $skipped = 0;
            foreach ($rows as $row) {
                if (isset($existing[$row['fitid']])) {
                    $skipped++;

                    continue;
                }
                $other = BankAccount::find((int) ($row['transfer_account_id'] ?? 0));
                if (! $other || $other->id === $account->id) {
                    throw new InvalidArgumentException('Escolha a outra conta da transferência: '.$row['description']);
                }
                $amount = round((float) $row['amount'], 2);
                $out = (float) $row['signed'] < 0;
                [$from, $to] = $out ? [$account, $other] : [$other, $account];
                $counter = \App\Domains\Banking\Services\Statements\StatementImportAnalyzer::findCounterpart($account->id, $other->id, $out ? -$amount : $amount, $row['date']);
                if ($counter) {
                    $counter = Transaction::query()->lockForUpdate()->findOrFail($counter->id);
                    $mine = Transaction::create([
                        'type' => 'transfer', 'date' => $row['date'], 'amount' => $out ? -$amount : $amount,
                        'description' => ($out ? 'Transferência Saída ' : 'Transferência Entrada ')."{$from->name} → {$to->name}",
                        'notes' => $row['notes'] ?? null, 'status' => 'settled', 'bank_account_id' => $account->id,
                        'ofx_fitid' => $row['fitid'], 'related_transaction_id' => $counter->id,
                    ]);
                    $counter->update(['type' => 'transfer', 'amount' => $out ? abs((float) $counter->amount) : -abs((float) $counter->amount),
                        'related_transaction_id' => $mine->id, 'category_id' => null]);
                    $linked++;
                } else {
                    $first = app(TransferService::class)->execute($from, $to, $amount, $row['date'], null, $row['notes'] ?? null);
                    $mine = $out ? $first : $first->related;
                    $mine->update(['ofx_fitid' => $row['fitid']]);
                    $created++;
                }
            }

            return ['created' => $created, 'linked' => $linked, 'skipped' => $skipped];
        });
    }

    public function importCard(CreditCard $card, string $referenceMonth, array $transactions): array
    {
        return DB::transaction(function () use ($card, $referenceMonth, $transactions) {
            $card = CreditCard::query()->lockForUpdate()->findOrFail($card->id);
            $existing = $this->existingFitids($card, $transactions);
            $invoices = app(InvoiceService::class);
            $invoice = $invoices->findOrCreateForReference($card, Carbon::createFromFormat('!Y-m', $referenceMonth));
            $invoice = $invoice->newQuery()->lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isOpen()) {
                throw new InvalidArgumentException('A fatura selecionada está fechada ou paga. Escolha uma fatura aberta.');
            }
            $imported = 0;
            foreach ($transactions as $row) {
                if (isset($existing[$row['fitid']]) || ($row['card_kind'] ?? '') === 'payment') {
                    continue;
                }
                Transaction::create([
                    'type' => 'expense', 'date' => $row['date'],
                    'amount' => ($row['card_kind'] === 'refund' ? '-' : '').$row['amount'],
                    'description' => $row['description'], 'notes' => $row['notes'],
                    'status' => 'settled', 'category_id' => $row['category_id'] ?? null,
                    'credit_card_id' => $card->id, 'credit_card_invoice_id' => $invoice->id,
                    'ofx_fitid' => $row['fitid'],
                ]);
                $imported++;
            }
            $invoices->recalculateTotal($invoice);
            return ['imported' => $imported, 'skipped' => count($transactions) - $imported];
        });
    }

    /**
     * Valor do TRNAMT tolerante a formatos de banco: "-1234.56", "-1.234,56", "1,234.56" e o da Caixa "-8.629.72"
     * (ponto como milhar e como decimal). O último separador seguido de 1 ou 2 dígitos é o decimal.
     *
     * @return array{0: string, 1: string, 2: string, 3?: string}|null  [inteiro, sinal, parte inteira, decimais]
     */
    private function amountParts(string $raw): ?array
    {
        $v = str_replace([' ', "\u{A0}", 'R$'], '', trim($raw));
        $sign = '';
        if ($v !== '' && ($v[0] === '-' || $v[0] === '+')) {
            $sign = $v[0];
            $v = substr($v, 1);
        } elseif (str_ends_with($v, '-')) {
            $sign = '-';
            $v = substr($v, 0, -1);
        }
        if (! preg_match('/^\d[\d.,]*$/', $v)) {
            return null;
        }
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $v, $m)) {
            $int = preg_replace('/[.,]/', '', $m[1]);
            $dec = $m[2];
        } else {
            $int = preg_replace('/[.,]/', '', $v);
            $dec = '';
        }
        if ($int === '' || strlen($int) > 13) {
            return null;
        }

        return $dec === '' ? [$raw, $sign, $int] : [$raw, $sign, $int, $dec];
    }

    private function field(string $contents, string $tag): string
    {
        if (! preg_match('~<'.$tag.'\b[^>]*>\s*([^<]*)~i', $contents, $match)) {
            return '';
        }

        return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function toUtf8(string $contents): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (preg_match('/^\xFF\xFE|^\xFE\xFF/', $contents)) {
            $converted = @iconv('UTF-16', 'UTF-8', $contents);

            if ($converted === false) {
                throw new InvalidArgumentException('A codificação do arquivo OFX não pôde ser lida.');
            }

            return $converted;
        }

        if (preg_match('/(?:^|\n)CHARSET:\s*(\d+)/i', $contents, $match)) {
            $encoding = match ($match[1]) {
                '1252' => 'Windows-1252',
                '8859', '1' => 'ISO-8859-1',
                '65001' => 'UTF-8',
                default => null,
            };

            if ($encoding && $encoding !== 'UTF-8') {
                $converted = @iconv($encoding, 'UTF-8//IGNORE', $contents);

                if ($converted !== false) {
                    return $converted;
                }
            }
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }
}
