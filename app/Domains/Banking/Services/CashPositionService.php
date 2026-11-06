<?php

namespace App\Domains\Banking\Services;

use App\Domains\Banking\Models\BankAccount;
use Illuminate\Support\Collection;

/** Cash sitting in real accounts, third-party obligations, and what is left for the holder. */
class CashPositionService
{
    /**
     * @param  Collection<int, BankAccount>|null  $accounts
     * @param  string|null  $until  data (Y-m-d) da posição; null = todos os lançamentos liquidados
     * @return array{accounts_balance: float, third_party_balance: float, own_balance: float, repasse_balance: float}
     */
    public function summarize(?Collection $accounts = null, ?string $until = null): array
    {
        $accounts ??= BankAccount::active()->get();

        $accountsBalance = (float) $accounts
            ->where('type', '!=', 'repasse')
            ->sum(fn (BankAccount $account) => $account->balance($until));

        $repasseBalance = (float) $accounts
            ->where('type', 'repasse')
            ->sum(fn (BankAccount $account) => $account->balance($until));

        // A negative repasse balance is money still owed. A positive balance is an over-settlement, not cash held for someone else.
        $thirdParty = max(0.0, -$repasseBalance);

        return [
            'accounts_balance' => round($accountsBalance, 2),
            'third_party_balance' => round($thirdParty, 2),
            'own_balance' => round($accountsBalance - $thirdParty, 2),
            'repasse_balance' => round($repasseBalance, 2),
        ];
    }
}
