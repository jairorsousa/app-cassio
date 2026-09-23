<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OfxImportService
{
    /**
     * @return array{account: ?string, transactions: array<int, array{fitid: string, date: string, amount: string, type: string, description: string, notes: ?string}>}
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('O arquivo OFX deve ter no máximo 2 MB.');
        }

        $contents = $this->toUtf8($contents);

        if (! preg_match('/<OFX\b[^>]*>/i', $contents)) {
            throw new InvalidArgumentException('O arquivo não contém uma estrutura OFX válida.');
        }

        $statementCount = preg_match_all('/<BANKTRANLIST\b/i', $contents);

        if ($statementCount !== 1 || ! preg_match('/<\/BANKTRANLIST\s*>/i', $contents)) {
            throw new InvalidArgumentException('Selecione um OFX com um único extrato de conta bancária.');
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

            if ($fitid === '' || mb_strlen($fitid) > 255 || ! preg_match('/^\d{8}/', $dateValue)) {
                throw new InvalidArgumentException('O lançamento '.($index + 1).' não possui identificador ou data válida.');
            }

            $year = (int) substr($dateValue, 0, 4);
            $month = (int) substr($dateValue, 4, 2);
            $day = (int) substr($dateValue, 6, 2);

            if (! checkdate($month, $day, $year)) {
                throw new InvalidArgumentException('O lançamento '.($index + 1).' possui data inválida.');
            }

            if (! preg_match('/^([+-]?)(\d{1,13})(?:\.(\d{1,2}))?$/', $amountValue, $amountParts)) {
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

            if (isset($seen[$fitid])) {
                throw new InvalidArgumentException('O OFX contém identificadores de lançamento repetidos: '.$fitid);
            }

            $seen[$fitid] = true;
            $description = $name !== '' ? $name : $memo;

            $transactions[] = [
                'fitid' => $fitid,
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'amount' => $amount,
                'type' => ($amountParts[1] ?? '') === '-' ? 'expense' : 'income',
                'description' => mb_substr($description, 0, 200),
                'notes' => $memo !== '' && $memo !== $name ? $memo : null,
            ];
        }

        return [
            'account' => $this->field($contents, 'ACCTID') ?: null,
            'transactions' => $transactions,
        ];
    }

    /** @param array<int, array<string, string|null>> $transactions */
    public function existingFitids(BankAccount $account, array $transactions): array
    {
        $found = [];

        foreach (array_chunk(array_column($transactions, 'fitid'), 500) as $chunk) {
            foreach (Transaction::withTrashed()
                ->where('bank_account_id', $account->id)
                ->whereIn('ofx_fitid', $chunk)
                ->pluck('ofx_fitid') as $fitid) {
                $found[$fitid] = true;
            }
        }

        return $found;
    }

    /** @param array<int, array<string, string|null>> $transactions
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
                    'bank_account_id' => $account->id,
                    'ofx_fitid' => $row['fitid'],
                ]);
                $imported++;
            }

            return ['imported' => $imported, 'skipped' => count($transactions) - $imported];
        });
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
