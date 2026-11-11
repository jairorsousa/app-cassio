<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetDividend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoriasProventosReembolsosTest extends TestCase
{
    use RefreshDatabase;

    private function dividend(string $type = 'dividend'): AssetDividend
    {
        $class = AssetClass::create(['name' => 'Ações', 'slug' => 'acoes']);
        $asset = Asset::create(['ticker' => 'PETR4', 'name' => 'Petrobras', 'asset_class_id' => $class->id]);
        $bank = BankAccount::create(['name' => 'XP', 'type' => 'investment', 'initial_balance' => 0]);

        return AssetDividend::create(['asset_id' => $asset->id, 'payment_date' => now()->subDay()->toDateString(), 'type' => $type,
            'unit_amount' => 1, 'quantity' => 10, 'total' => 10, 'bank_account_id' => $bank->id]);
    }

    public function test_provento_automatico_grava_categoria_proventos_pela_chave(): void
    {
        $rend = Category::create(['name' => 'Rendimento de Investimentos', 'type' => 'income', 'status' => true]);
        $prov = Category::create(['name' => 'Ações e FIIs (Proventos)', 'type' => 'income', 'status' => true, 'parent_id' => $rend->id]);
        DB::table('categories')->where('id', $prov->id)->update(['system_key' => Category::DIVIDEND_INCOME_KEY, 'name' => 'Proventos renomeado']);

        foreach (['dividend', 'jcp', 'fii'] as $i => $type) {
            $div = $this->dividend($type);
            $tx = $div->transactions()->first();
            $this->assertNotNull($tx, $type);
            $this->assertSame($prov->id, (int) $tx->category_id, $type);
            if ($i === 0) {
                break; // AssetClass slug único: um tipo basta para o listener
            }
        }
    }

    public function test_sync_grava_categoria_sem_sobrescrever_escolha_manual(): void
    {
        $div = $this->dividend('jcp');
        $prov = Category::dividendIncomeCategoryId();
        $tx = $div->transactions()->first();
        $this->assertSame($prov, (int) $tx->category_id);

        $outra = Category::create(['name' => 'Outra receita', 'type' => 'income', 'status' => true]);
        $tx->update(['category_id' => $outra->id]);
        $div->update(['total' => 12]);
        $this->assertSame($outra->id, (int) $div->transactions()->first()->category_id);

        DB::table('transactions')->where('id', $tx->id)->update(['category_id' => null]);
        $div->update(['total' => 13]);
        $this->assertSame($prov, (int) $div->transactions()->first()->category_id);
    }

    public function test_migration_renomeia_honorarios_e_cria_reembolsos_e_conta_remunerada(): void
    {
        $now = now();
        $trab = DB::table('categories')->insertGetId(['name' => 'Remuneração do Trabalho', 'type' => 'income', 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $var = DB::table('categories')->insertGetId(['name' => 'Variável (Comissões/Bônus)', 'type' => 'income', 'parent_id' => $trab, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $outras = DB::table('categories')->insertGetId(['name' => 'Outras Receitas', 'type' => 'income', 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $rend = DB::table('categories')->insertGetId(['name' => 'Rendimento de Investimentos', 'type' => 'income', 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $prov = DB::table('categories')->insertGetId(['name' => 'Ações e FIIs (Proventos)', 'type' => 'income', 'parent_id' => $rend, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $migration = require database_path('migrations/2026_11_11_070000_categorias_honorarios_reembolsos_rendimentos.php');
        $migration->up();
        $migration->up(); // idempotente

        $this->assertSame('Honorários (minha parte)', DB::table('categories')->where('id', $var)->value('name'));
        $this->assertSame(1, DB::table('categories')->where('name', 'Reembolsos e Ressarcimentos')->where('type', 'income')->where('parent_id', $outras)->count());
        $this->assertSame(1, DB::table('categories')->where('name', 'Reembolsos e Ressarcimentos')->where('type', 'expense')->count());
        $this->assertSame(1, DB::table('categories')->where('name', 'Conta remunerada / rendimento automático')->where('parent_id', $rend)->count());
        $this->assertSame('dividend_income', DB::table('categories')->where('id', $prov)->value('system_key'));
    }
}
