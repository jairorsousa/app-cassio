<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Padroniza nomes das contas (autorizado pelo Cassio em 05/11/2026). IDs e vínculos não mudam. */
return new class extends Migration
{
    private array $names = [
        'Banco do Brasil_CC' => 'Banco do Brasil',
        'Banco Itaú' => 'Itaú',
        'CEF_CC' => 'CEF',
        'Banco Bradesco_CC' => 'Bradesco',
        'Banco BTG_CC' => 'BTG',
        'Banco Xp' => 'XP',
        'Banco do Brasil_FI' => 'BB Fundo (aplicação automática)',
        'Banco do Brasil_CI' => 'BB Conta investimento',
    ];

    public function up(): void
    {
        foreach ($this->names as $old => $new) {
            DB::table('bank_accounts')->where('name', $old)->update(['name' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach ($this->names as $old => $new) {
            DB::table('bank_accounts')->where('name', $new)->update(['name' => $old]);
        }
    }
};
