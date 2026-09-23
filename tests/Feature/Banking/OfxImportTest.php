<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\OfxImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OfxImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_previews_and_imports_sgml_statement_without_reimporting_the_same_fitid(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');
        $account = BankAccount::create(['name' => 'Conta principal']);
        $file = UploadedFile::fake()->createWithContent('extrato.ofx', $this->sgmlStatement());

        Volt::test('banking.transactions.index')
            ->call('openImport')
            ->set('ofxAccountId', $account->id)
            ->set('ofxFile', $file)
            ->call('previewImport')
            ->assertHasNoErrors()
            ->assertRedirect(route('banking.transactions.index', ['preview' => 'ofx']));

        $this->get(route('banking.transactions.index', ['preview' => 'ofx']))
            ->assertOk()
            ->assertSee('Mercado')
            ->assertSee('Salario');

        Volt::test('banking.transactions.import-preview')
            ->assertSee('Mercado')
            ->assertSee('Salario')
            ->assertSee('R$ 1.000,00')
            ->assertSee('R$ 34,50')
            ->call('confirmImport')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseHas('transactions', [
            'bank_account_id' => $account->id,
            'ofx_fitid' => 'bank-001',
            'type' => 'expense',
            'amount' => '34.50',
            'description' => 'Mercado',
            'status' => 'settled',
        ]);
        $this->assertSame(965.50, $account->fresh()->balance());

        Volt::test('banking.transactions.index')
            ->call('openImport')
            ->set('ofxAccountId', $account->id)
            ->set('ofxFile', UploadedFile::fake()->createWithContent('extrato.ofx', $this->sgmlStatement()))
            ->call('previewImport')
            ->assertRedirect(route('banking.transactions.index', ['preview' => 'ofx']));

        Volt::test('banking.transactions.import-preview')
            ->assertSee('2 já importada(s)')
            ->call('confirmImport')
            ->assertHasErrors(['import']);

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_fitid_is_scoped_to_bank_account_and_imported_transactions_are_editable(): void
    {
        $service = app(OfxImportService::class);
        $rows = $service->parse($this->sgmlStatement())['transactions'];
        $first = BankAccount::create(['name' => 'Primeira']);
        $second = BankAccount::create(['name' => 'Segunda']);

        $this->assertSame(['imported' => 2, 'skipped' => 0], $service->import($first, $rows));
        $this->assertSame(['imported' => 2, 'skipped' => 0], $service->import($second, $rows));
        $this->assertDatabaseCount('transactions', 4);

        $transaction = Transaction::where('bank_account_id', $first->id)->firstOrFail();
        $this->assertFalse($transaction->isReadOnly());

        $transaction->delete();
        $this->assertSame(['imported' => 0, 'skipped' => 2], $service->import($first, $rows));
    }

    public function test_full_preview_groups_all_rows_and_applies_category_and_exclusions(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');
        $account = BankAccount::create(['name' => 'Conta completa']);
        $category = Category::create(['name' => 'Receitas diversas', 'type' => 'income']);

        Volt::test('banking.transactions.index')
            ->call('openImport')
            ->set('ofxAccountId', $account->id)
            ->set('ofxFile', UploadedFile::fake()->createWithContent('completo.ofx', $this->manyRowsStatement(25)))
            ->call('previewImport')
            ->assertRedirect(route('banking.transactions.index', ['preview' => 'ofx']));

        Volt::test('banking.transactions.import-preview')
            ->assertSee('Item 25')
            ->assertSee('R$ 130,00')
            ->assertSee('R$ 24,00')
            ->assertSeeInOrder(['Receitas', 'Item 25', 'Despesas'])
            ->set('categorySelections.0', $category->id)
            ->call('exclude', 24)
            ->assertSee('Retirado');

        Volt::test('banking.transactions.import-preview')
            ->assertSet('categorySelections.0', $category->id)
            ->assertSee('Retirado')
            ->call('confirmImport')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('transactions', 24);
        $this->assertDatabaseHas('transactions', [
            'ofx_fitid' => 'many-1',
            'category_id' => $category->id,
        ]);
        $this->assertDatabaseMissing('transactions', ['ofx_fitid' => 'many-25']);
    }

    public function test_preview_rejects_category_from_the_wrong_transaction_type(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');
        $account = BankAccount::create(['name' => 'Conta']);
        $expenseCategory = Category::create(['name' => 'Mercado', 'type' => 'expense']);

        Volt::test('banking.transactions.index')
            ->call('openImport')
            ->set('ofxAccountId', $account->id)
            ->set('ofxFile', UploadedFile::fake()->createWithContent('extrato.ofx', $this->sgmlStatement()))
            ->call('previewImport');

        Volt::test('banking.transactions.import-preview')
            ->set('categorySelections.1', $expenseCategory->id)
            ->call('confirmImport')
            ->assertHasErrors(['import']);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cancel_discards_the_preview_without_importing(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');
        $account = BankAccount::create(['name' => 'Conta']);

        Volt::test('banking.transactions.index')
            ->call('openImport')
            ->set('ofxAccountId', $account->id)
            ->set('ofxFile', UploadedFile::fake()->createWithContent('extrato.ofx', $this->sgmlStatement()))
            ->call('previewImport');

        Volt::test('banking.transactions.import-preview')
            ->call('cancel')
            ->assertRedirect(route('banking.transactions.index'));

        $this->assertDatabaseCount('transactions', 0);
        $this->assertCount(0, Storage::disk('local')->files('banking/ofx-previews'));
    }

    public function test_parses_xml_ofx_and_rejects_invalid_transactions_before_import(): void
    {
        $service = app(OfxImportService::class);
        $xml = '<?xml version="1.0"?><OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><ACCTID>1234</ACCTID></BANKACCTFROM><BANKTRANLIST><STMTTRN><DTPOSTED>20260910120000[-3:BRT]</DTPOSTED><TRNAMT>100.05</TRNAMT><FITID>xml-1</FITID><NAME>Cliente &amp; Cia</NAME></STMTTRN></BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';
        $parsed = $service->parse($xml);

        $this->assertSame('1234', $parsed['account']);
        $this->assertSame('Cliente & Cia', $parsed['transactions'][0]['description']);
        $this->assertSame('income', $parsed['transactions'][0]['type']);
        $this->assertSame('100.05', $parsed['transactions'][0]['amount']);

        $this->expectException(\InvalidArgumentException::class);
        $service->parse(str_replace('20260910120000', '20260230120000', $xml));
    }

    public function test_rejects_non_ofx_file_in_upload_flow(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');
        $account = BankAccount::create(['name' => 'Conta']);

        Volt::test('banking.transactions.index')
            ->call('openImport')
            ->set('ofxAccountId', $account->id)
            ->set('ofxFile', UploadedFile::fake()->createWithContent('invalid.ofx', 'not an OFX'))
            ->call('previewImport')
            ->assertHasErrors(['ofxFile']);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_reads_windows_1252_text_and_rejects_incomplete_statement(): void
    {
        $service = app(OfxImportService::class);
        $statement = str_replace('Mercado', 'Pão', $this->sgmlStatement());
        $encoded = iconv('UTF-8', 'Windows-1252', $statement);

        $this->assertSame('Pão', $service->parse($encoded)['transactions'][0]['description']);

        $this->expectException(\InvalidArgumentException::class);
        $service->parse(str_replace('</STMTTRN>', '', $statement));
    }

    public function test_rejects_credit_card_statement_for_bank_account_import(): void
    {
        $service = app(OfxImportService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->parse(str_replace('BANKTRANLIST', 'CCTRANLIST', $this->sgmlStatement()));
    }

    private function sgmlStatement(): string
    {
        return "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nSECURITY:NONE\nENCODING:USASCII\nCHARSET:1252\n\n".
            '<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><ACCTID>1234</BANKACCTFROM>'.
            '<BANKTRANLIST><STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260910<TRNAMT>-34.50<FITID>bank-001<NAME>Mercado<MEMO>Compra de alimentos</STMTTRN>'.
            '<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260911<TRNAMT>1000.00<FITID>bank-002<NAME>Salario</STMTTRN>'.
            '</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';
    }

    private function manyRowsStatement(int $count): string
    {
        $rows = '';

        for ($index = 1; $index <= $count; $index++) {
            $amount = $index % 2 === 1 ? '10.00' : '-2.00';
            $rows .= "<STMTTRN><DTPOSTED>20260901<TRNAMT>{$amount}<FITID>many-{$index}<NAME>Item {$index}</STMTTRN>";
        }

        return "<OFX><BANKTRANLIST>{$rows}</BANKTRANLIST></OFX>";
    }
}
