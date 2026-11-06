<?php

namespace Tests\Feature\Investments;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Investments\Models\BrokerStatementImport;
use App\Domains\Investments\Services\Statements\BankAccountCsvParser;
use App\Domains\Investments\Services\Statements\TabularFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Amostras sanitizadas (valores e nomes fictícios). */
class BankAccountCsvParserTest extends TestCase
{
    use RefreshDatabase;

    // Decrescente, como exportado; mesmo dia com PIX recebido e devolução parcial (não compensar).
    private const JAN = "Data;Descrição;Valor;Saldo\n"
        ."31/01/2026;Rendimento automático;R$ 1,10;R$ 1.234,10\n"
        ."20/01/2026;Pagamento financiamento FINANCEIRA X;-R$ 500,00;R$ 1.233,00\n"
        ."15/01/2026;Pagamento BANCO EXEMPLO;-R$ 300,00;R$ 1.733,00\n"
        ."15/01/2026;PIX devolvido FULANO;-R$ 2.700,00;R$ 2.033,00\n"
        ."15/01/2026;PIX recebido FULANO;R$ 3.000,00;R$ 4.733,00\n"
        ."10/01/2026;TED enviada;-R$ 100,00;R$ 1.733,00\n"
        ."10/01/2026;TED enviada;-R$ 100,00;R$ 1.833,00\n"
        ."02/01/2026;Rendimento automático;R$ 0,50;R$ 1.933,00\n";

    private const FEB = "Data;Descrição;Valor;Saldo\n"
        ."10/02/2026;Tarifa;-R$ 5,00;R$ 1.229,10\n"
        ."31/01/2026;Rendimento automático;R$ 1,10;R$ 1.234,10\n"; // sobreposição com o arquivo anterior

    private function parse(string $csv, string $tag = 'a'): array
    {
        $t = app(TabularFileReader::class)->csv($csv);

        return app(BankAccountCsvParser::class)->parse($t['headers'], $t['rows'], $tag);
    }

    public function test_reverses_desc_order_and_reconciles_with_derived_opening(): void
    {
        $p = $this->parse(self::JAN);
        $r = $p['reconciliation'];
        $this->assertSame('desc_invertido', $r['order']);
        $this->assertSame(8, $r['rows']);
        $this->assertSame('2026-01-02', $r['from']);
        $this->assertSame(1932.5, $r['opening_derived']);
        $this->assertSame(3001.6, $r['credits']);
        $this->assertSame(3700.0, $r['debits']);
        $this->assertSame(1234.1, $r['last_balance']);
        $this->assertTrue($r['ok']);
        // mesmo dia: ordem exata do arquivo invertida (recebido → devolvido → pagamento)
        $this->assertSame(['transfer', 'transfer_return', 'other'], array_column(array_slice($p['rows'], 3, 3), 'kind'));
        $this->assertSame([3000.0, -2700.0, -300.0], array_column(array_slice($p['rows'], 3, 3), 'amount'));
        $this->assertSame('loan_payment', $p['rows'][6]['kind']);
        $this->assertSame('cash_yield', $p['rows'][0]['kind']);
        // duas TEDs idênticas no mesmo arquivo continuam distintas
        $this->assertNotSame($p['rows'][1]['fingerprint'], $p['rows'][2]['fingerprint']);
    }

    public function test_gap_is_reported_with_missing_amount(): void
    {
        $csv = str_replace("15/01/2026;Pagamento BANCO EXEMPLO;-R$ 300,00;R$ 1.733,00\n", '', self::JAN);
        $r = $this->parse($csv)['reconciliation'];
        $this->assertFalse($r['ok']);
        $this->assertCount(1, $r['mismatches']);
        $this->assertSame(-300.0, $r['mismatches'][0]['gap']);
    }

    public function test_combine_files_removes_overlap_and_checks_join(): void
    {
        $parser = app(BankAccountCsvParser::class);
        $c = $parser->combine([$this->parse(self::FEB, 'fev'), $this->parse(self::JAN, 'jan')]);
        $this->assertSame('2026-01-02', $c['from']);
        $this->assertSame('2026-02-10', $c['to']);
        $this->assertSame(1, $c['duplicates_removed']);
        $this->assertCount(9, $c['rows']);
        $this->assertTrue($c['joins'][0]['continuous']);
        $this->assertTrue($c['ok']);
        $this->assertSame(2, $c['by_kind']['cash_yield']['count']);
    }

    public function test_debit_credit_columns_quotes_and_plain_numbers(): void
    {
        $csv = "\"Data\",\"Histórico\",\"Débito\",\"Crédito\",\"Saldo\"\n\"01/03/2026\",\"PIX enviado\",\"1.000,00\",\"\",\"4.000,00\"\n\"02/03/2026\",\"Rendimento automático\",\"\",\"0,40\",\"4.000,40\"\n";
        $p = $this->parse($csv);
        $this->assertSame('asc', $p['reconciliation']['order']);
        $this->assertSame(5000.0, $p['reconciliation']['opening_derived']);
        $this->assertSame([-1000.0, 0.4], array_column($p['rows'], 'amount'));
        $this->assertTrue($p['reconciliation']['ok']);
        $this->assertNull(BankAccountCsvParser::detect(['Data', 'Ativo', 'Valor']));
    }

    public function test_preview_command_is_read_only(): void
    {
        $a = tempnam(sys_get_temp_dir(), 'c');
        $b = tempnam(sys_get_temp_dir(), 'c');
        file_put_contents($a, self::JAN);
        file_put_contents($b, self::FEB);
        $tx = Transaction::count();
        $this->artisan('investments:account-csv-preview', ['files' => [$a, $b]])->expectsOutputToContain('"duplicates_removed": 1')->expectsOutputToContain('nada foi gravado')->assertSuccessful();
        $this->assertSame($tx, Transaction::count());
        $this->assertSame(0, BrokerStatementImport::count());
    }
}
