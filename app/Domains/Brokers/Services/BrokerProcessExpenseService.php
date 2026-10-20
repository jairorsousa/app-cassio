<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerProcessExpense;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BrokerProcessExpenseService
{
    public function save(Broker $broker, array $data, ?BrokerProcessExpense $expense = null): BrokerProcessExpense
    {
        $data = Validator::make($data, [
            'date' => 'required|date|before_or_equal:'.now('America/Sao_Paulo')->toDateString(),
            'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/', 'numeric', 'min:0.01'],
            'description' => 'required|string|max:200',
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('status', true)->whereNull('deleted_at')],
        ])->validate();

        return DB::transaction(function () use ($broker, $data, $expense) {
            Broker::lockForUpdate()->findOrFail($broker->id);
            if ($expense) {
                $expense = BrokerProcessExpense::where('broker_id', $broker->id)->lockForUpdate()->findOrFail($expense->id);
                if ($expense->recoveries()->exists()) {
                    throw new \DomainException('Desfaça os descontos desta despesa nos recebimentos antes de alterá-la.');
                }
                $expense->update($data);
            } else {
                $expense = BrokerProcessExpense::create($data + ['broker_id' => $broker->id]);
            }
            $transactionData = [
                'type' => 'expense', 'status' => 'settled', 'date' => $data['date'], 'amount' => $data['amount'],
                'description' => mb_substr('Despesa de processos · '.$broker->name.' · '.$data['description'], 0, 200),
                'bank_account_id' => $data['bank_account_id'], 'source_type' => BrokerProcessExpense::class, 'source_id' => $expense->id,
            ];
            $transactions = app(TransactionService::class);
            if ($expense->transaction) {
                $transactions->updateGenerated($expense->transaction, $transactionData);
            } else {
                $transaction = $transactions->create($transactionData);
                $expense->update(['transaction_id' => $transaction->id]);
            }

            return $expense->fresh();
        });
    }

    public function delete(BrokerProcessExpense $expense): void
    {
        DB::transaction(function () use ($expense) {
            Broker::lockForUpdate()->findOrFail($expense->broker_id);
            $expense = BrokerProcessExpense::lockForUpdate()->findOrFail($expense->id);
            if ($expense->recoveries()->exists()) {
                throw new \DomainException('Desfaça os descontos desta despesa nos recebimentos antes de excluí-la.');
            }
            if ($expense->transaction) {
                app(TransactionService::class)->deleteGenerated($expense->transaction);
            }
            $expense->delete();
        });
    }
}
