<?php

namespace Tests\Feature\Investments;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\B3MidasImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Segunda data-base B3 (dados sintéticos): coluna cotacao_* nova, PM preservado, data de provento corrigida sem duplicar, taxas de aluguel da movimentação. */
class B3SecondBaseDateImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const H = "ticker,quantidade,preco_medio,%s,valor_mercado,distancia_medio_pct,preco_medio_status,origem_b3,instituicoes,produto\n";

    private const IH = "data,ticker,tipo,quantidade,valor_bruto,valor_liquido,eh_reembolso_aluguel,classe,corretora,fonte_arquivo\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        foreach ([['Ações', 'acoes'], ['FIIs', 'fiis'], ['BDRs', 'bdrs']] as [$n, $s]) {
            AssetClass::create(['name' => $n, 'slug' => $s]);
        }
    }

    private function importPackage(string $pos, string $inc, string $base, ?string $mov = null): array
    {
        $svc = app(B3MidasImportService::class);
        $p = $svc->preview($pos, $inc, $base, null, $mov);
        $this->assertTrue($p['ok'], implode(' | ', $p['errors']));
        $batch = InvestmentImportBatch::create(['source' => 'b3_midas', 'status' => 'preview', 'base_date' => $base]);
        $svc->confirm($p, $batch, $this->user->id);

        return $batch->fresh()->summary;
    }

    public function test_second_base_date_corrects_date_keeps_pm_and_imports_lending_fees_idempotently(): void
    {
        $inc1 = self::IH."2026-10-01,AAAA3,JCP,100,,6.33,nao,Ação,XP,a\n2026-09-01,AAAA3,JCP,100,,6.33,nao,Ação,XP,a\n2026-10-01,CCCC3,JCP,10,,1.00,nao,Ação,XP,a\n";
        $this->importPackage(sprintf(self::H, 'cotacao_30out')."AAAA3,100,10.00,12.00,1200.00,,confirmado,custodia,X,AAAA3 - A\nBBBB3,1000,,2.00,2000.00,,nao_informado,custodia+emprestimo_doador,X,BBBB3 - B\nENGI3,10,,1.00,10.00,,nao_informado,emprestimo_doador,X,ENGI3 - E\n", $inc1, '2026-10-30');
        // Extrato da corretora já registrou o pagamento de CCCC3 em 03/11.
        AssetIncomeEvent::create(['ticker' => 'CCCC3', 'payment_date' => '2026-11-03', 'type' => 'jcp', 'quantity' => 10, 'net_amount' => 1.00, 'broker_name' => 'XP', 'source' => 'broker_statement', 'source_key' => 'stmt:x:1']);
        $txBefore = Transaction::withTrashed()->count();

        $pos2 = sprintf(self::H, 'cotacao_06nov')."AAAA3,100,,13.00,1300.00,,nao_informado,custodia,X,AAAA3 - A\nBBBB3,500,,2.00,1000.00,,nao_informado,emprestimo_doador,X,BBBB3 - B\n";
        $inc2 = self::IH."2026-11-03,AAAA3,JCP,100,,6.33,nao,Ação,XP,a\n2026-09-01,AAAA3,JCP,100,,6.33,nao,Ação,XP,a\n2026-11-03,CCCC3,JCP,10,,1.00,nao,Ação,XP,a\n";
        $mov = "entrada_saida,data,movimentacao,ticker,produto,instituicao,quantidade,preco_unitario,valor_operacao\n"
            ."Credito,2026-11-06,Empréstimo,BBBB3,BBBB3 - B,BANCO BTG PACTUAL S/A.,0,,3.49\n"
            ."Credito,2026-11-06,Empréstimo,BBBB3,BBBB3 - B,BANCO BTG PACTUAL S/A.,500,,\n"
            ."Debito,2026-11-03,Transferência - Liquidação,BBBB3,BBBB3 - B,BANCO BTG PACTUAL S/A.,500,5.01,2505.0\n";
        $s = $this->importPackage($pos2, $inc2, '2026-11-06', $mov);

        $snap = B3PositionSnapshot::latestConfirmed()->keyBy('ticker');
        $this->assertSame(['AAAA3', 'BBBB3'], $snap->keys()->all()); // ENGI3 saiu
        $this->assertSame('10.0000', $snap['AAAA3']->average_price); // PM já registrado prevalece
        $this->assertSame('500.000000', $snap['BBBB3']->quantity_lent);
        $aaaa = AssetIncomeEvent::where('ticker', 'AAAA3')->orderBy('payment_date')->pluck('payment_date')->map->toDateString()->all();
        $this->assertSame(['2026-09-01', '2026-11-03'], $aaaa); // movido, não duplicado
        $this->assertSame(1, AssetIncomeEvent::where('ticker', 'CCCC3')->count()); // fica só o do extrato
        $this->assertSame('broker_statement', AssetIncomeEvent::where('ticker', 'CCCC3')->value('source'));
        $this->assertSame(1, AssetIncomeEvent::where('source', 'b3_movimentacao')->where('type', 'lending_fee')->count());
        $this->assertSame(['positions' => 2, 'income' => 0, 'income_dates_corrected' => 1, 'income_removed_duplicate' => 1, 'lending_fees' => 1], $s['created']);

        // Reimportar o mesmo pacote não muda nada.
        $count = AssetIncomeEvent::count();
        $again = $this->importPackage($pos2, $inc2, '2026-11-06', $mov);
        $this->assertSame(['positions' => 0, 'income' => 0, 'income_dates_corrected' => 0, 'income_removed_duplicate' => 0, 'lending_fees' => 0], $again['created']);
        $this->assertSame($count, AssetIncomeEvent::count());
        $this->assertSame($txBefore, Transaction::withTrashed()->count()); // Financeiro intocado
    }
}
