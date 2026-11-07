<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\Statements\GenericStatementReader;
use App\Domains\Investments\Services\Statements\TabularFileReader;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;
use ZipArchive;

class StatementImportTest extends TestCase
{
    use RefreshDatabase;

    private function xlsx(array $rows): string
    {
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $r) {
            $xml .= '<row r="'.($ri + 1).'">';
            foreach ($r as $ci => $v) {
                $ref = chr(65 + $ci).($ri + 1);
                $xml .= is_string($v) ? '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($v).'</t></is></c>' : '<c r="'.$ref.'"><v>'.$v.'</v></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE);
        $z->addFromString('xl/worksheets/sheet1.xml', $xml);
        $z->close();

        return $path;
    }

    public function test_xlsx_with_title_rows_and_excel_dates(): void
    {
        $p = $this->xlsx([
            ['', 'Extrato da conta'], ['', 'CASSIO', '', 'Conta XP: 1'], ['', 'Saldo total', '', 3102.19],
            ['', 'Movimentação', 'Liquidação', 'Lançamento', 'Valor (R$)', 'Saldo (R$)'],
            ['', 46329, 46329, 'JUROS S/ CAPITAL BBDC4', 63.32, 3102.19],
            ['', 46244, 46244, 'TED RETIRADA EM C/C', -1613.77, 0],
            ['', 'Lançamentos futuros'],
        ]);
        $r = app(GenericStatementReader::class)->read($p, 'xlsx');
        $this->assertCount(2, $r['rows']);
        $this->assertSame('2026-11-03', $r['rows'][0]['date']);
        $this->assertSame(63.32, $r['rows'][0]['amount']);
        $this->assertSame(-1613.77, $r['rows'][1]['amount']);
        $this->assertStringContainsString('cabeçalho na linha 4', $r['source']);
    }

    public function test_xlsx_without_header_infers_columns(): void
    {
        $p = $this->xlsx([
            ['05/10/2026', 'POSTO SANTA TEREZA', '-150,17', '1.000,00'],
            ['06/10/2026', 'SALARIO', '5.000,00', '6.000,00'],
            ['07/10/2026', 'MERCADO', '-80,00', '5.920,00'],
        ]);
        $r = app(GenericStatementReader::class)->read($p, 'xlsx');
        $this->assertCount(3, $r['rows']);
        $this->assertSame(-150.17, $r['rows'][0]['amount']);
        $this->assertSame('POSTO SANTA TEREZA', $r['rows'][0]['description']);
        $this->assertSame(1000.0, $r['rows'][0]['balance']);
    }

    public function test_tabular_reader_prefers_keyword_header(): void
    {
        $p = $this->xlsx([['Relatório', 'Cliente X', 'Conta 1'], ['Data', 'Ativo', 'Valor'], ['01/01/2026', 'PETR4', '10,00']]);
        $r = app(TabularFileReader::class)->read($p, 'xlsx');
        $this->assertSame(['Data', 'Ativo', 'Valor'], $r['headers']);
        $this->assertCount(1, $r['rows']);
    }

    public function test_pdf_text_and_money_formats(): void
    {
        $reader = app(GenericStatementReader::class);
        $r = $reader->fromText("Extrato\n01/10/2026 Saldo Inicial 2.000,00\n05/10/2026  PIX ENVIADO FULANO   -1.234,56   2.000,00\n06/10/2026 RENDIMENTO 0,08\nRodapé", 'PDF');
        $this->assertCount(2, $r['rows']);
        $this->assertSame(-1234.56, $r['rows'][0]['amount']);
        $this->assertSame(-2869.73, $reader->parseMoney('-R$ 2.869,73'));
        $this->assertSame(-12.0, $reader->parseMoney('(12,00)'));
        $this->assertSame(-5.5, $reader->parseMoney('5,50 D'));
    }

    public function test_review_flags_and_nothing_saved_until_confirm(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user);
        $acc = BankAccount::forceCreate(['name' => 'Conta A', 'type' => 'checking', 'initial_balance' => 0]);
        $other = BankAccount::forceCreate(['name' => 'Conta B', 'type' => 'checking', 'initial_balance' => 0]);
        Transaction::forceCreate(['type' => 'expense', 'date' => '2026-10-05', 'amount' => '150.17', 'description' => 'POSTO SANTA TEREZA', 'status' => 'settled', 'bank_account_id' => $acc->id]);
        Transaction::forceCreate(['type' => 'income', 'date' => '2026-10-07', 'amount' => '500.00', 'description' => 'TED recebida', 'status' => 'settled', 'bank_account_id' => $other->id]);
        $before = Transaction::count();
        $csv = "Data;Descrição;Valor\n05/10/2026;POSTO SANTA TEREZA;-150,17\n06/10/2026;MERCADO;-80,00\n07/10/2026;ENVIO PARA CONTA B;-500,00\n06/10/2026;POSTO SANTA TEREZ;-150,17\n";
        $file = UploadedFile::fake()->createWithContent('extrato.csv', $csv);
        $c = Volt::test('banking.transactions.statement-import')->set('accountId', $acc->id)->set('file', $file)->call('analyzeFile')->assertHasNoErrors();
        $statuses = array_column($c->get('rows'), 'status');
        $this->assertSame(['exists', 'new', 'transfer', 'duplicate'], $statuses);
        $this->assertSame($before, Transaction::count());
        $c->call('confirmImport')->assertHasNoErrors();
        $this->assertSame($before + 1, Transaction::count());
        $this->assertTrue(Transaction::where('description', 'MERCADO')->where('type', 'expense')->where('amount', '80.00')->exists());
        // reimportar o mesmo arquivo: MERCADO agora aparece como já existente
        $c2 = Volt::test('banking.transactions.statement-import')->set('accountId', $acc->id)->set('file', UploadedFile::fake()->createWithContent('extrato.csv', $csv))->call('analyzeFile');
        $this->assertSame('exists', $c2->get('rows')[1]['status']);
        $this->get(route('banking.transactions.statement-import'))->assertOk()->assertSee('Importar extrato ou fatura');
    }

    public function test_photo_without_ai_gives_clear_message(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user);
        $acc = BankAccount::forceCreate(['name' => 'Conta A', 'type' => 'checking', 'initial_balance' => 0]);
        config(['services.statement_ai.key' => null]);
        Volt::test('banking.transactions.statement-import')->set('accountId', $acc->id)
            ->set('file', UploadedFile::fake()->image('extrato.jpg'))->call('analyzeFile')->assertHasErrors('file');
    }
}
