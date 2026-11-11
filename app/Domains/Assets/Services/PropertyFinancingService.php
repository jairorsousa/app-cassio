<?php

namespace App\Domains\Assets\Services;

use App\Domains\Assets\Models\Property;
use App\Domains\Assets\Models\PropertyDownPayment;
use App\Domains\Assets\Models\PropertyFinancing;
use App\Domains\Assets\Models\PropertyFinancingInstallment;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\LoanService;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Dashboard\Services\DashboardSnapshotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Financiamento de bens (Opção A): entrada + parcelas geradas.
 * A parcela inteira vai para "Aquisição de bem" (fora do resultado); juros só no painel do bem.
 * Data anterior ao saldo inicial da conta: só histórico, sem lançamento.
 * A partir do saldo inicial: gera lançamento ou permite vincular a um já existente no extrato.
 */
class PropertyFinancingService
{
    public function __construct(private TransactionService $transactions) {}

    /**
     * @param  array{creditor:string,financed_amount:string|float,installment_count:int,installment_amount:string|float,first_due_date:string,interest_rate_am?:?string,amortization_system?:?string,notes?:?string}  $data
     */
    public function createFinancing(Property $property, array $data): PropertyFinancing
    {
        $clean = $this->cleanFinancing($data);

        return DB::transaction(function () use ($property, $clean) {
            $financing = $property->financings()->create($clean + ['created_by' => auth()->id()]);
            $this->generateInstallments($financing);
            $this->invalidate();

            return $financing->fresh(['installments']);
        });
    }

    /**
     * @param  array{status?:string,date:?string,amount:string|float,bank_account_id?:?int,notes?:?string}  $data
     * @return array{down:PropertyDownPayment,warnings:list<string>,duplicates:Collection}|PropertyDownPayment
     */
    public function addDownPayment(Property $property, array $data, bool $confirmed = false, ?int $linkTransactionId = null): array|PropertyDownPayment
    {
        $clean = $this->cleanDown($data);
        if ($clean['status'] === 'paid' && $clean['bank_account_id'] && $clean['date'] && ! $confirmed && ! $linkTransactionId) {
            $warnings = $this->transactionWarnings((int) $clean['bank_account_id'], (float) $clean['amount'], $clean['date']);
            if ($warnings['warnings']) {
                return $warnings + ['down' => null];
            }
        }

        return DB::transaction(function () use ($property, $clean, $linkTransactionId) {
            $down = $property->downPayments()->create([
                'status' => $clean['status'],
                'date' => $clean['date'],
                'amount' => $clean['amount'],
                'bank_account_id' => $clean['bank_account_id'],
                'notes' => $clean['notes'],
                'created_by' => auth()->id(),
            ]);
            if ($down->status === 'paid') {
                $this->settle($down, $clean['date'], $clean['bank_account_id'], $linkTransactionId, $this->downDescription($down));
            }
            $this->invalidate();

            return $down->fresh(['transaction', 'bankAccount']);
        });
    }

    /**
     * Marca uma parcela como paga. Conta é opcional (pode preencher depois).
     * Sem conta, ou com data anterior ao saldo inicial: só histórico, sem lançamento.
     *
     * @param  array{paid_at:string,bank_account_id?:?int,notes?:?string}  $data
     * @return array{installment:PropertyFinancingInstallment,warnings:list<string>,duplicates:Collection}|PropertyFinancingInstallment
     */
    public function markInstallmentPaid(PropertyFinancingInstallment $installment, array $data, bool $confirmed = false, ?int $linkTransactionId = null): array|PropertyFinancingInstallment
    {
        if (! $installment->isOpen()) {
            throw new \DomainException('Esta parcela já está paga.');
        }
        $clean = $this->cleanMarkPaid($data);
        $accountId = $clean['bank_account_id'];
        $paidAt = $clean['paid_at'];
        if ($accountId && $paidAt && ! $confirmed && ! $linkTransactionId) {
            $warnings = $this->transactionWarnings($accountId, (float) $installment->amount, $paidAt);
            if ($warnings['warnings']) {
                return $warnings + ['installment' => $installment];
            }
        }

        return DB::transaction(function () use ($installment, $clean, $linkTransactionId) {
            $installment->update([
                'status' => 'paid',
                'paid_at' => $clean['paid_at'],
                'bank_account_id' => $clean['bank_account_id'],
                'notes' => $clean['notes'] ?? $installment->notes,
                'category_id' => Category::assetPurchaseCategoryId(),
            ]);
            $this->settle($installment->fresh(), $clean['paid_at'], $clean['bank_account_id'], $linkTransactionId, $this->installmentDescription($installment->fresh()));
            $this->invalidate();

            return $installment->fresh(['transaction', 'bankAccount', 'financing.property']);
        });
    }

    /**
     * Baixa em lote. Cada item: id da parcela => paid_at, bank_account_id (opcional), link_transaction_id (opcional).
     * Se alguma tiver aviso de duplicata e $confirmed for false, não grava nada e devolve os avisos.
     *
     * @param  array<int, array{paid_at:string,bank_account_id?:?int,link_transaction_id?:?int}>  $rows
     * @return array{ok:bool,warnings:array<int, list<string>>,duplicates:array<int, Collection>}|list<PropertyFinancingInstallment>
     */
    public function markInstallmentsPaid(array $rows, bool $confirmed = false): array
    {
        $installments = PropertyFinancingInstallment::with('financing.property')->whereIn('id', array_keys($rows))->get()->keyBy('id');
        if ($installments->count() !== count($rows)) {
            throw new \DomainException('Uma ou mais parcelas não foram encontradas.');
        }
        $warningsById = [];
        $dupsById = [];
        if (! $confirmed) {
            foreach ($rows as $id => $data) {
                $inst = $installments[$id];
                if (! $inst->isOpen()) {
                    throw new \DomainException('A parcela '.$inst->number.' já está paga.');
                }
                $clean = $this->cleanMarkPaid($data);
                $link = ! empty($data['link_transaction_id']) ? (int) $data['link_transaction_id'] : null;
                if ($clean['bank_account_id'] && $clean['paid_at'] && ! $link) {
                    $w = $this->transactionWarnings((int) $clean['bank_account_id'], (float) $inst->amount, $clean['paid_at']);
                    if ($w['warnings']) {
                        $warningsById[$id] = $w['warnings'];
                        $dupsById[$id] = $w['duplicates'];
                    }
                }
            }
            if ($warningsById) {
                return ['ok' => false, 'warnings' => $warningsById, 'duplicates' => $dupsById];
            }
        }

        return DB::transaction(function () use ($rows, $installments) {
            $done = [];
            foreach ($rows as $id => $data) {
                $link = ! empty($data['link_transaction_id']) ? (int) $data['link_transaction_id'] : null;
                $done[] = $this->markInstallmentPaid($installments[$id], $data, confirmed: true, linkTransactionId: $link);
            }

            return $done;
        });
    }

    /** Desfaz o pagamento: apaga o lançamento gerado, ou desvincula o do extrato (devolvendo a categoria). */
    public function unpayInstallment(PropertyFinancingInstallment $installment): PropertyFinancingInstallment
    {
        if (! $installment->isPaid()) {
            throw new \DomainException('Esta parcela ainda não está paga.');
        }

        return DB::transaction(function () use ($installment) {
            $this->unsettle($installment);
            $installment->update([
                'status' => 'open',
                'paid_at' => null,
                'transaction_id' => null,
                'linked_existing' => false,
                'linked_previous_category_id' => null,
            ]);
            $this->invalidate();

            return $installment->fresh();
        });
    }

    public function deleteDownPayment(PropertyDownPayment $down): void
    {
        DB::transaction(function () use ($down) {
            $this->unsettle($down);
            $down->delete();
            $this->invalidate();
        });
    }

    /** Só apaga o financiamento se nenhuma parcela estiver paga. */
    public function deleteFinancing(PropertyFinancing $financing): void
    {
        if ($financing->installments()->where('status', 'paid')->exists()) {
            throw new \DomainException('Há parcelas já pagas. Desfaça esses pagamentos antes de apagar o financiamento.');
        }
        DB::transaction(function () use ($financing) {
            $financing->delete();
            $this->invalidate();
        });
    }

    /** Lançamentos na conta com o mesmo valor e data próxima, que ainda não são pagamento deste financiamento/entrada. */
    public function possibleDuplicates(int $accountId, float $amount, string $date): Collection
    {
        $day = Carbon::parse($date);

        return Transaction::where('bank_account_id', $accountId)
            ->whereIn('type', ['expense', 'transfer'])
            ->whereBetween('date', [$day->copy()->subDays(3)->startOfDay(), $day->copy()->addDays(3)->endOfDay()])
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere(function ($q) {
                $q->where('source_type', '!=', PropertyFinancingInstallment::class)
                    ->where('source_type', '!=', PropertyDownPayment::class);
            }))
            ->get(['id', 'date', 'amount', 'description', 'type', 'category_id'])
            ->filter(fn ($t) => (int) round(abs((float) $t->amount) * 100) === (int) round($amount * 100))
            ->values();
    }

    /** Data anterior ao saldo inicial da conta: o pagamento fica só no histórico do bem. */
    public function isHistorical(?int $accountId, ?string $date): bool
    {
        if (! $accountId || ! $date) {
            return false;
        }
        $account = BankAccount::withTrashed()->find($accountId);

        return $account?->isHistoricalDate($date) ?? false;
    }

    private function cleanFinancing(array $data): array
    {
        $creditor = trim((string) ($data['creditor'] ?? ''));
        if ($creditor === '') {
            throw new \DomainException('Informe o banco ou credor do financiamento.');
        }
        $financed = round((float) ($data['financed_amount'] ?? 0), 2);
        $installment = round((float) ($data['installment_amount'] ?? 0), 2);
        $count = (int) ($data['installment_count'] ?? 0);
        if ($financed <= 0) {
            throw new \DomainException('Informe o valor financiado.');
        }
        if ($installment <= 0) {
            throw new \DomainException('Informe o valor da parcela.');
        }
        if ($count < 1 || $count > 240) {
            throw new \DomainException('Informe o número de parcelas (1 a 240).');
        }
        if (empty($data['first_due_date'])) {
            throw new \DomainException('Informe a data da primeira parcela.');
        }
        $first = Carbon::parse($data['first_due_date'])->toDateString();
        $rate = isset($data['interest_rate_am']) && $data['interest_rate_am'] !== '' && $data['interest_rate_am'] !== null
            ? round((float) $data['interest_rate_am'], 4) : null;
        if ($rate !== null && $rate < 0) {
            throw new \DomainException('A taxa não pode ser negativa.');
        }
        $system = in_array($data['amortization_system'] ?? null, ['price', 'sac'], true) ? $data['amortization_system'] : null;
        if ($rate && ! $system) {
            $system = 'price';
        }

        return [
            'creditor' => mb_substr($creditor, 0, 120),
            'financed_amount' => number_format($financed, 2, '.', ''),
            'installment_count' => $count,
            'installment_amount' => number_format($installment, 2, '.', ''),
            'first_due_date' => $first,
            'interest_rate_am' => $rate,
            'amortization_system' => $system,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
        ];
    }

    private function cleanDown(array $data): array
    {
        $status = in_array($data['status'] ?? 'paid', ['paid', 'planned'], true) ? ($data['status'] ?? 'paid') : throw new \DomainException('Escolha se a entrada já foi paga.');
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \DomainException('Informe o valor da entrada.');
        }
        $date = ! empty($data['date']) ? Carbon::parse($data['date'])->toDateString() : null;
        $accountId = ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        if ($status === 'paid' && ! $date) {
            throw new \DomainException('Informe a data da entrada.');
        }
        if ($accountId && ! BankAccount::whereKey($accountId)->exists()) {
            throw new \DomainException('Conta inválida.');
        }

        return [
            'status' => $status,
            'date' => $date,
            'amount' => number_format($amount, 2, '.', ''),
            'bank_account_id' => $accountId,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
        ];
    }

    private function cleanMarkPaid(array $data): array
    {
        if (empty($data['paid_at'])) {
            throw new \DomainException('Informe a data em que a parcela foi paga.');
        }
        $paidAt = Carbon::parse($data['paid_at'])->toDateString();
        $accountId = ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        if ($accountId && ! BankAccount::whereKey($accountId)->exists()) {
            throw new \DomainException('Conta inválida.');
        }

        return [
            'paid_at' => $paidAt,
            'bank_account_id' => $accountId,
            'notes' => array_key_exists('notes', $data) && $data['notes'] !== null && trim((string) $data['notes']) !== ''
                ? mb_substr(trim((string) $data['notes']), 0, 500) : null,
        ];
    }

    private function generateInstallments(PropertyFinancing $financing): void
    {
        $first = Carbon::parse($financing->first_due_date);
        $amount = number_format((float) $financing->installment_amount, 2, '.', '');
        $rows = [];
        $now = now();
        for ($n = 1; $n <= (int) $financing->installment_count; $n++) {
            $rows[] = [
                'property_financing_id' => $financing->id,
                'number' => $n,
                'due_date' => $first->copy()->addMonthsNoOverflow($n - 1)->toDateString(),
                'amount' => $amount,
                'status' => 'open',
                'created_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        PropertyFinancingInstallment::insert($rows);
    }

    /**
     * @return array{warnings:list<string>,duplicates:Collection}
     */
    private function transactionWarnings(int $accountId, float $amount, string $date): array
    {
        if ($this->isHistorical($accountId, $date)) {
            return ['warnings' => [], 'duplicates' => collect()];
        }
        $dups = $this->possibleDuplicates($accountId, $amount, $date);
        $warnings = [];
        foreach ($dups as $dup) {
            $warnings[] = 'Já existe nesta conta um lançamento de R$ '.number_format(abs((float) $dup->amount), 2, ',', '.').' em '.$dup->date->format('d/m/Y').' ("'.mb_substr((string) $dup->description, 0, 50).'"), talvez vindo do extrato. Você pode vincular a este lançamento (ele passa a "Aquisição de bem" e sai do resultado) em vez de criar outro.';
        }

        return ['warnings' => $warnings, 'duplicates' => $dups];
    }

    private function settle(PropertyDownPayment|PropertyFinancingInstallment $row, ?string $date, ?int $accountId, ?int $linkTransactionId, string $description): void
    {
        if (! $accountId || ! $date) {
            return;
        }
        if ($this->isHistorical($accountId, $date)) {
            return;
        }
        $categoryId = Category::assetPurchaseCategoryId();
        $amount = (float) $row->amount;
        $sourceType = $row instanceof PropertyDownPayment ? PropertyDownPayment::class : PropertyFinancingInstallment::class;

        if ($linkTransactionId) {
            $tx = Transaction::whereKey($linkTransactionId)->where('bank_account_id', $accountId)->first();
            if (! $tx) {
                throw new \DomainException('O lançamento escolhido para vincular não foi encontrado nesta conta.');
            }
            if ((int) round(abs((float) $tx->amount) * 100) !== (int) round($amount * 100)) {
                throw new \DomainException('O lançamento escolhido não tem o mesmo valor desta parcela.');
            }
            $previous = $tx->category_id;
            $tx->update([
                'source_type' => $sourceType,
                'source_id' => $row->id,
                'category_id' => $categoryId,
            ]);
            $row->update([
                'transaction_id' => $tx->id,
                'linked_existing' => true,
                'linked_previous_category_id' => $previous,
                'bank_account_id' => $accountId,
            ]);

            return;
        }

        $tx = $this->transactions->create([
            'type' => 'expense',
            'status' => 'settled',
            'date' => $date,
            'amount' => $amount,
            'description' => mb_substr($description, 0, 180),
            'bank_account_id' => $accountId,
            'category_id' => $categoryId,
            'source_type' => $sourceType,
            'source_id' => $row->id,
        ]);
        $row->update(['transaction_id' => $tx->id, 'linked_existing' => false, 'bank_account_id' => $accountId]);
    }

    private function unsettle(PropertyDownPayment|PropertyFinancingInstallment $row): void
    {
        $tx = $row->transaction;
        if (! $tx) {
            return;
        }
        if ($row->linked_existing) {
            $tx->update([
                'source_type' => null,
                'source_id' => null,
                'category_id' => $row->linked_previous_category_id,
            ]);

            return;
        }
        $this->transactions->deleteGenerated($tx);
    }

    private function downDescription(PropertyDownPayment $down): string
    {
        $property = $down->property;

        return 'Bem: '.$property->description.' · Entrada'.($down->notes ? ' · '.$down->notes : '');
    }

    private function installmentDescription(PropertyFinancingInstallment $installment): string
    {
        $fin = $installment->financing;
        $property = $fin->property;
        $total = (int) $fin->installment_count;

        return 'Bem: '.$property->description.' · Parcela '.$installment->number.'/'.$total.' · '.$fin->creditor;
    }

    private function invalidate(): void
    {
        app(LoanService::class)->invalidate();
        app(DashboardSnapshotService::class)->refresh();
    }
}
