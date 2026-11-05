<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Saldos iniciais reais em 01/10/2026 (informados pelo Cassio) e vínculo das contas de investimento do BB.
 * Idempotente: grava os mesmos valores em cada execução. Confere id + nome para não atingir outra conta.
 */
return new class extends Migration
{
    private array $balances = [
        1 => ['Banco do Brasil', 84927.38],
        4 => ['Itaú', 68802.72],
        3 => ['CEF', 66888.02],
        2 => ['Bradesco', 29885.23],
        6 => ['BTG', 14425.81],
        12 => ['XP', 1376.39],
    ];

    public function up(): void
    {
        foreach ($this->balances as $id => [$name, $amount]) {
            DB::table('bank_accounts')->where('id', $id)->where('name', $name)
                ->update(['initial_balance' => $amount, 'initial_balance_date' => '2026-10-01', 'updated_at' => now()]);
        }
        DB::table('bank_accounts')->whereIn('id', [10, 14])->where('type', 'investment')
            ->whereIn('name', ['BB Fundo (aplicação automática)', 'BB Conta investimento'])
            ->update(['linked_account_id' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Valores anteriores estão no backup /root/app-cassio-backups/etapa1-saldos-antes-20261105.json.
        DB::table('bank_accounts')->whereIn('id', [10, 14])->update(['linked_account_id' => null]);
    }
};
