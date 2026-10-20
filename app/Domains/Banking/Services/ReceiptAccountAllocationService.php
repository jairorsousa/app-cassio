<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Transaction;

class ReceiptAccountAllocationService
{
    public const CLIENT_ACCOUNT = 'Cliente';

    public const OFFICE_ACCOUNT = 'Mota e Silva Advogados';

    /** Keep an already assigned account even if it was renamed. Never guess among duplicates. */
    public function target(Transaction $receipt, string $kind): BankAccount
    {
        $name = $kind === 'client' ? self::CLIENT_ACCOUNT : self::OFFICE_ACCOUNT;
        $assignedId = $receipt->destinations()->where('kind', $kind)->value('repasse_account_id');
        $accounts = BankAccount::active()->where('type', 'repasse')->lockForUpdate();
        $matches = $assignedId
            ? $accounts->whereKey($assignedId)->get()
            : $accounts->get()->filter(fn ($account) => mb_strtolower(trim($account->name)) === mb_strtolower($name));
        if ($matches->count() !== 1) {
            throw new \DomainException($assignedId
                ? 'A conta de repasse vinculada a '.$name.' deve estar ativa e ser do tipo Repasse.'
                : 'Cadastre uma única conta chamada '.$name.' com o tipo Repasse e mantenha-a ativa antes de salvar a divisão.');
        }
        $account = $matches->first();
        if ($account->id === $receipt->bank_account_id) {
            throw new \DomainException('A conta do recebimento deve ser diferente das contas de repasse.');
        }

        return $account;
    }

    public function sync(ReceiptDestination $part, BankAccount $target): void
    {
        $receipt = $part->receipt;
        $amount = (int) round((float) $part->amount * 100) - ($part->kind === 'client' ? $part->recoveredCents() : 0);
        if ($amount <= 0) {
            $this->remove($part);
            $part->update(['repasse_account_id' => $target->id]);

            return;
        }
        $transactions = app(TransactionService::class);
        $money = intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
        if ($part->allocation_transaction_id) {
            $out = Transaction::findOrFail($part->allocation_transaction_id);
            $transactions->updateGenerated($out, ['amount' => '-'.$money]);
            $transactions->updateGenerated($out->related()->firstOrFail(), ['amount' => $money]);

            return;
        }
        $source = $receipt->bankAccount()->withTrashed()->firstOrFail();
        $common = [
            'type' => 'transfer', 'date' => $receipt->date->toDateString(), 'status' => 'settled',
            'source_type' => ReceiptDestination::class, 'source_id' => $part->id,
            'notes' => 'Distribuição do recebimento #'.$receipt->id.' · '.$part->beneficiary,
        ];
        $out = $transactions->create($common + [
            'amount' => '-'.$money, 'bank_account_id' => $source->id,
            'description' => mb_substr('Transferência Saída '.$source->name.' → '.$target->name, 0, 200),
        ]);
        $in = $transactions->create($common + [
            'amount' => $money, 'bank_account_id' => $target->id, 'related_transaction_id' => $out->id,
            'description' => mb_substr('Transferência Entrada '.$source->name.' → '.$target->name, 0, 200),
        ]);
        $out->update(['related_transaction_id' => $in->id]);
        $part->update(['repasse_account_id' => $target->id, 'allocation_transaction_id' => $out->id]);
    }

    public function remove(ReceiptDestination $part): void
    {
        if (! $part->allocation_transaction_id) {
            return;
        }
        $out = Transaction::findOrFail($part->allocation_transaction_id);
        $in = $out->related()->firstOrFail();
        $part->update(['allocation_transaction_id' => null]);
        app(TransactionService::class)->deleteGenerated($in);
        app(TransactionService::class)->deleteGenerated($out);
    }

    public function clear(Transaction $receipt): void
    {
        foreach ($receipt->destinations()->whereNotNull('allocation_transaction_id')->lockForUpdate()->get() as $part) {
            $this->remove($part);
        }
    }
}
