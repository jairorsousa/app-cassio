<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 1 (dados): nada é excluído.
 * - Cria a categoria de sistema "Transferência entre contas" e aplica às transferências sem categoria.
 * - Marca is_test nos registros de teste conhecidos.
 * - Unifica "Repasse cliente" (id 11) em "Cliente" (id 13) e desativa a conta 11.
 * Backup lógico feito antes em /root/app-cassio-backups/.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $categoryId = DB::table('categories')->where('system_key', 'transfer')->value('id')
            ?? DB::table('categories')->insertGetId([
                'name' => 'Transferência entre contas', 'type' => 'transfer', 'status' => true,
                'is_system' => true, 'system_key' => 'transfer', 'created_at' => $now, 'updated_at' => $now,
            ]);
        DB::table('transactions')->where('type', 'transfer')->whereNull('category_id')->update(['category_id' => $categoryId]);

        // Testes: palavra "teste" isolada na descrição (inclui excluídos), sem regex do banco para funcionar também no SQLite.
        $ids = DB::table('transactions')->where('description', 'like', '%teste%')->get(['id', 'description'])
            ->filter(fn ($row) => preg_match('/(^|[^\pL])teste([^\pL]|$)/iu', (string) $row->description))->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('transactions')->whereIn('id', $ids)->update(['is_test' => true]);
        }
        DB::table('contacts')->whereIn('name', ['JAIRO DOIS', 'JAIRO RODRIGUES', 'TESTE TESTE'])->update(['is_test' => true]);
        $receivables = DB::table('receivables')->whereRaw('LOWER(description) = ?', ['teste'])
            ->whereIn('contact_id', DB::table('contacts')->where('name', 'JAIRO RODRIGUES')->pluck('id'))->pluck('id');
        if ($receivables->isNotEmpty()) {
            DB::table('receivables')->whereIn('id', $receivables)->update(['is_test' => true]);
        }

        // Unificação Cliente/Repasse cliente (apenas se os ids corresponderem aos nomes esperados).
        $from = DB::table('bank_accounts')->where('id', 11)->where('name', 'Repasse cliente')->first();
        $to = DB::table('bank_accounts')->where('id', 13)->where('name', 'Cliente')->where('type', 'repasse')->first();
        if ($from && $to) {
            foreach (['transactions' => 'bank_account_id', 'recurring_transactions' => 'bank_account_id', 'receipt_destinations' => 'repasse_account_id'] as $table => $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::table($table)->where($column, 11)->update([$column => 13]);
                }
            }
            DB::table('bank_accounts')->where('id', 11)->update([
                'status' => false,
                'notes' => trim(($from->notes ?? '')."\nUnificada em \"Cliente\" (id 13) em 05/11/2026."),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $categoryId = DB::table('categories')->where('system_key', 'transfer')->value('id');
        if ($categoryId) {
            DB::table('transactions')->where('category_id', $categoryId)->update(['category_id' => null]);
            DB::table('categories')->where('id', $categoryId)->delete();
        }
        foreach (['transactions', 'contacts', 'receivables'] as $table) {
            DB::table($table)->update(['is_test' => false]);
        }
        DB::table('bank_accounts')->where('id', 11)->where('name', 'Repasse cliente')->update(['status' => true]);
    }
};
