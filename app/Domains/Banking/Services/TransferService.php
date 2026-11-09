<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function execute(
        BankAccount $from,
        BankAccount $to,
        float $amount,
        Carbon|string $date,
        ?string $description = null,
        ?string $notes = null,
        string $status = 'settled',
    ): Transaction {
        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Conta origem e destino devem ser diferentes.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor da transferência deve ser positivo.');
        }

        if (! in_array($status, ['pending', 'settled'], true)) {
            throw new \InvalidArgumentException('Status da transferência inválido.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $date, $description, $notes, $status) {
            $outDescription = $description ?? "Transferência Saída {$from->name} → {$to->name}";
            $inDescription = $description ?? "Transferência Entrada {$from->name} → {$to->name}";

            $out = Transaction::create([
                'type' => 'transfer',
                'date' => $date,
                'amount' => -$amount,
                'description' => $outDescription,
                'notes' => $notes,
                'status' => $status,
                'bank_account_id' => $from->id,
            ]);

            $in = Transaction::create([
                'type' => 'transfer',
                'date' => $date,
                'amount' => $amount,
                'description' => $inDescription,
                'notes' => $notes,
                'status' => $status,
                'bank_account_id' => $to->id,
                'related_transaction_id' => $out->id,
            ]);

            $out->update(['related_transaction_id' => $in->id]);

            return $out->fresh();
        });
    }

    /** Descrição automática (gerada sem descrição do usuário) pode ser refeita com os nomes novos das contas. */
    public static function isAutoDescription(?string $description): bool
    {
        return $description === null || $description === '' || str_starts_with($description, 'Transferência Saída ') || str_starts_with($description, 'Transferência Entrada ');
    }

    /**
     * Edita uma transferência (ou converte um lançamento comum em transferência) mantendo os DOIS lados coerentes:
     * saída negativa na conta de origem, entrada positiva na conta de destino, mesma data/valor/status, ligadas entre si.
     * Contas antigas e novas ficam certas porque o saldo é a soma dos lançamentos de cada conta.
     * Transferência de um lado só (vínculo importado) continua de um lado só se a outra conta não for informada.
     */
    public function update(
        Transaction $edited,
        ?BankAccount $from,
        ?BankAccount $to,
        float $amount,
        Carbon|string $date,
        ?string $notes = null,
        string $status = 'settled',
    ): Transaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Valor da transferência deve ser positivo.');
        }
        if (! in_array($status, ['pending', 'settled'], true)) {
            throw new \InvalidArgumentException('Status da transferência inválido.');
        }
        if ($from && $to && $from->id === $to->id) {
            throw new \InvalidArgumentException('Conta origem e destino devem ser diferentes.');
        }

        return DB::transaction(function () use ($edited, $from, $to, $amount, $date, $notes, $status) {
            $edited = Transaction::lockForUpdate()->findOrFail($edited->id);
            $related = $edited->related_transaction_id ? Transaction::lockForUpdate()->find($edited->related_transaction_id) : null;
            $related ??= Transaction::where('related_transaction_id', $edited->id)->where('type', 'transfer')->lockForUpdate()->first();
            foreach (array_filter([$edited, $related]) as $side) {
                if ($side->isReadOnly()) {
                    throw new \DomainException('Lançamento gerado por outro módulo é somente leitura.');
                }
                if ($side->destinations()->exists() || $side->destinationPayment()->exists()) {
                    throw new \DomainException('Remova a divisão do recebimento ou desvincule o repasse antes de alterar esta transferência.');
                }
            }
            if ($edited->credit_card_id) {
                throw new \DomainException('Lançamento de cartão não pode virar transferência entre contas.');
            }
            $wasTransfer = $edited->type === 'transfer';

            // Qual lado é a saída: no par, o negativo; sem par, o sinal do lançamento (convertido: despesa = saída, receita = entrada).
            if ($related) {
                if ((float) $edited->amount < 0) {
                    [$out, $in] = [$edited, $related];
                } elseif ((float) $related->amount < 0) {
                    [$out, $in] = [$related, $edited];
                } else {
                    [$out, $in] = [$edited, $related]; // par inconsistente (dois positivos): o editado vira a saída
                }
            } else {
                $editedIsOut = $wasTransfer ? (float) $edited->amount < 0 : $edited->type !== 'income';
                [$out, $in] = $editedIsOut ? [$edited, null] : [null, $edited];
            }

            if ((! $from && ! $to) || ($related && (! $from || ! $to)) || (! $related && $out && ! $from) || (! $related && $in && ! $to)) {
                throw new \InvalidArgumentException('Selecione as contas de origem e destino.');
            }

            $autoOut = ! $out || self::isAutoDescription($out->description);
            $autoIn = ! $in || self::isAutoDescription($in->description);
            $names = $from && $to ? "{$from->name} → {$to->name}" : null;
            $common = ['type' => 'transfer', 'date' => $date, 'status' => $status, 'notes' => $notes, 'credit_card_id' => null, 'additional_card_id' => null, 'credit_card_invoice_id' => null];

            if ($from) {
                $outData = $common + ['amount' => -$amount, 'bank_account_id' => $from->id];
                if ($autoOut && $names) {
                    $outData['description'] = "Transferência Saída {$names}";
                }
                if ($out) {
                    $out->update($outData);
                } elseif ($to) {
                    $out = Transaction::create($outData + ['description' => $outData['description'] ?? "Transferência Saída {$names}"]);
                }
            }
            if ($to) {
                $inData = $common + ['amount' => $amount, 'bank_account_id' => $to->id];
                if ($autoIn && $names) {
                    $inData['description'] = "Transferência Entrada {$names}";
                }
                if ($in) {
                    $in->update($inData);
                } elseif ($from) {
                    $in = Transaction::create($inData + ['description' => $inData['description'] ?? "Transferência Entrada {$names}"]);
                }
            }
            if ($out && $in) {
                $out->forceFill(['related_transaction_id' => $in->id])->save();
                $in->forceFill(['related_transaction_id' => $out->id])->save();
            }

            return Transaction::findOrFail($edited->id);
        });
    }

    /** Transferência vira receita/despesa: o lado editado fica, o outro lado é excluído (sem órfão). */
    public function detach(Transaction $edited): void
    {
        DB::transaction(function () use ($edited) {
            $edited = Transaction::lockForUpdate()->findOrFail($edited->id);
            $related = $edited->related_transaction_id ? Transaction::lockForUpdate()->find($edited->related_transaction_id) : null;
            $related ??= Transaction::where('related_transaction_id', $edited->id)->where('type', 'transfer')->lockForUpdate()->first();
            if ($related) {
                if ($related->isReadOnly() || $related->destinations()->exists() || $related->destinationPayment()->exists()) {
                    throw new \DomainException('O outro lado desta transferência está vinculado a outro módulo; desvincule antes de mudar o tipo.');
                }
                $related->forceFill(['related_transaction_id' => null])->saveQuietly();
                $related->delete();
            }
            $edited->forceFill(['related_transaction_id' => null])->saveQuietly();
        });
    }
}
