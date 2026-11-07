<?php

namespace Tests\Feature;

use App\Domains\Banking\Services\Statements\GenericStatementReader;
use App\Domains\Banking\Services\Statements\StatementImportAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaixaStatementDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_txt_caixa_e_lido_e_ignora_saldo_dia(): void
    {
        $txt = "\"Conta\";\"Data_Mov\";\"Nr_Doc\";\"Historico\";\"Valor\";\"Deb_Cred\"\n"
            ."\"0315\";\"20261008\";\"003151\";\"CR LEV JUD\";\"30181.36\";\"C\"\n"
            ."\"0315\";\"20261008\";\"000000\";\"SALDO DIA\";\"0.00\";\"C\"\n"
            ."\"0315\";\"20261013\";\"102027\";\"DEB PIX CH\";\"21126.95\";\"D\"\n";
        $this->assertTrue(GenericStatementReader::isCaixaTxt($txt));
        $r = app(GenericStatementReader::class)->fromCaixaTxt($txt);
        $this->assertSame([['2026-10-08', 30181.36, '003151'], ['2026-10-13', -21126.95, '102027']],
            array_map(fn ($x) => [$x['date'], $x['amount'], $x['doc']], $r['rows']));
        $this->assertSame(1, $r['ignored_balance_lines']);
    }

    public function test_mesmo_documento_e_valor_em_outro_formato_nao_duplica(): void
    {
        $acc = DB::table('bank_accounts')->insertGetId(['name' => 'Caixa', 'bank' => 'Caixa', 'type' => 'checking', 'initial_balance' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        // Já importado pelo TXT/HTML (data de movimento 13/10).
        DB::table('transactions')->insert(['type' => 'expense', 'date' => '2026-10-13', 'amount' => 21126.95, 'description' => 'DEB PIX CH', 'status' => 'paid',
            'bank_account_id' => $acc, 'ofx_fitid' => 'doc:102027|21126.95|2026-10-13|1', 'created_at' => now(), 'updated_at' => now()]);
        // O PDF traz a data da operação (10/10) e outro texto, mas o mesmo Nr. Doc e valor.
        $rows = (new StatementImportAnalyzer)->analyze([
            ['date' => '2026-10-10', 'description' => 'DEB PIX CHAVE Cassio Mota E Silv', 'amount' => -21126.95, 'balance' => null, 'doc' => '102027'],
            ['date' => '2026-10-10', 'description' => 'DEB PIX CHAVE Mota E Silva Migno', 'amount' => -2322.55, 'balance' => null, 'doc' => '102216'],
        ], 'bank', $acc);
        $this->assertSame('exists', $rows[0]['status']);
        $this->assertNotSame('exists', $rows[1]['status']);
        $this->assertStringStartsWith('doc:102216|2322.55|', $rows[1]['fitid']);
    }
}
