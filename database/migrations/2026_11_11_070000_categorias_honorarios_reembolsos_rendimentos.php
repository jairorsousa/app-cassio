<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Categorias (aprovado por Cassio em 11/11/2026):
 * - "Variável (Comissões/Bônus)" → "Honorários (minha parte)" (mesmo id, vínculos mantidos);
 * - par "Reembolsos e Ressarcimentos" (receita em Outras Receitas + despesa), efeito líquido zero;
 * - "Conta remunerada / rendimento automático" em Rendimento de Investimentos;
 * - chave estável `dividend_income` em "Ações e FIIs (Proventos)" (proventos automáticos).
 * Idempotente. Usa DB direto (o model bloqueia só categorias is_system; nenhuma destas é).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('categories')->where('name', 'Variável (Comissões/Bônus)')->where('type', 'income')
            ->update(['name' => 'Honorários (minha parte)', 'updated_at' => $now]);

        $ensure = function (string $name, string $type, ?int $parentId) use ($now): void {
            $q = DB::table('categories')->where('name', $name)->where('type', $type);
            if ($q->exists()) {
                return;
            }
            DB::table('categories')->insert(['parent_id' => $parentId, 'name' => $name, 'type' => $type, 'status' => true, 'created_at' => $now, 'updated_at' => $now]);
        };

        $outras = DB::table('categories')->where('name', 'Outras Receitas')->where('type', 'income')->whereNull('parent_id')->value('id');
        // Só cria onde a árvore do Cassio existe (banco novo/testes ficam sem categorias, como antes).
        if ($outras) {
            $ensure('Reembolsos e Ressarcimentos', 'income', (int) $outras);
            $ensure('Reembolsos e Ressarcimentos', 'expense', null);
        }

        $rend = DB::table('categories')->where('name', 'Rendimento de Investimentos')->where('type', 'income')->whereNull('parent_id')->value('id');
        if ($rend) {
            $ensure('Conta remunerada / rendimento automático', 'income', (int) $rend);
        }

        $proventos = DB::table('categories')->where('name', 'Ações e FIIs (Proventos)')->where('type', 'income')->value('id');
        if ($proventos && ! DB::table('categories')->where('system_key', 'dividend_income')->exists()) {
            DB::table('categories')->where('id', $proventos)->update(['system_key' => 'dividend_income']);
        }
    }

    public function down(): void
    {
        DB::table('categories')->where('name', 'Honorários (minha parte)')->where('type', 'income')
            ->update(['name' => 'Variável (Comissões/Bônus)']);
        DB::table('categories')->where('system_key', 'dividend_income')->update(['system_key' => null]);
        foreach ([['Reembolsos e Ressarcimentos', 'income'], ['Reembolsos e Ressarcimentos', 'expense'], ['Conta remunerada / rendimento automático', 'income']] as [$n, $t]) {
            $id = DB::table('categories')->where('name', $n)->where('type', $t)->value('id');
            if ($id && ! DB::table('transactions')->where('category_id', $id)->exists() && ! DB::table('transaction_allocations')->where('category_id', $id)->exists()) {
                DB::table('categories')->where('id', $id)->delete();
            }
        }
    }
};
