<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StatementImportTransferTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true])->save();
        $this->actingAs($u);
    }

    private function accounts(): array
    {
        return [BankAccount::forceCreate(['name' => 'CEF', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]),
            BankAccount::forceCreate(['name' => 'Itaú', 'type' => 'checking', 'initial_balance' => 0, 'status' => true])];
    }

    private function csv(): string
    {
        return "Data;Descrição;Valor\n23/09/2026;SALDO DIA;0,00\n23/09/2026;- 06:04:00 230604 DEB PIX CHAVE Cassio Mota E Silv *** .394.813***;-35.000,00\n"
            ."22/09/2026;DEB PIX CHAVE Cassio Mota E Silv *** .394.813***;-50.000,00\n21/09/2026;RESG LCI VLR ATUAL;131.094,09\n20/09/2026;PADARIA;-12,50\n";
    }

    public function test_own_holder_rows_are_transfers_and_balance_rows_ignored(): void
    {
        $this->login();
        [$cef, $itau] = $this->accounts();
        $before = Transaction::count();
        $c = Volt::test('banking.transactions.statement-import')->set('accountId', $cef->id)
            ->set('file', UploadedFile::fake()->createWithContent('cef.csv', $this->csv()))->call('analyzeFile')->assertHasNoErrors();
        $rows = $c->get('rows');
        $this->assertCount(4, $rows);
        $this->assertGreaterThanOrEqual(1, $c->get('ignoredBalance') + $c->get('skipped'));
        $this->assertSame(['transfer', 'transfer', 'income', 'expense'], array_column($rows, 'type'));
        $this->assertSame('35.000,00', $rows[0]['amount']);
        $this->assertSame('DEB PIX CHAVE Cassio Mota E Silv *** .394.813***', $rows[0]['description']);
        $this->assertTrue($rows[0]['selected']);
        $c->assertSee('Transferência')->assertSee('− R$ 35.000,00', false)->assertDontSee('SALDO DIA');
        $this->assertSame($before, Transaction::count());

        // Itaú já tem a entrada de 50 mil lançada como receita em 22/09: vincula, não duplica.
        $existing = Transaction::forceCreate(['type' => 'income', 'date' => '2026-09-23', 'amount' => '50000.00', 'description' => 'PIX RECEBIDO CASSIO', 'status' => 'settled', 'bank_account_id' => $itau->id]);
        $c->set('rows.0.transfer_account_id', $itau->id)->set('rows.1.transfer_account_id', $itau->id);
        $this->assertSame($existing->id, $c->get('rows')[1]['transfer_link_id']);
        $c->set('rows.2.selected', false)->set('rows.3.selected', false)->call('confirmImport')->assertHasNoErrors();

        $out35 = Transaction::where('bank_account_id', $cef->id)->where('amount', -35000)->firstOrFail();
        $this->assertSame('transfer', $out35->type);
        $this->assertSame('Transferência Saída CEF → Itaú', $out35->description);
        $in35 = Transaction::findOrFail($out35->related_transaction_id);
        $this->assertSame([$itau->id, 35000.0, 'transfer', $out35->id], [(int) $in35->bank_account_id, (float) $in35->amount, $in35->type, (int) $in35->related_transaction_id]);

        $out50 = Transaction::where('bank_account_id', $cef->id)->where('amount', -50000)->firstOrFail();
        $existing->refresh();
        $this->assertSame([$existing->id, 'transfer', $out50->id], [(int) $out50->related_transaction_id, $existing->type, (int) $existing->related_transaction_id]);
        $this->assertSame(1, Transaction::where('bank_account_id', $itau->id)->where('amount', 50000)->count());
        $this->assertSame(0, Transaction::whereIn('type', ['income', 'expense'])->whereIn('bank_account_id', [$cef->id, $itau->id])->count());
    }

    public function test_type_selector_switches_row_to_transfer(): void
    {
        $this->login();
        [$cef, $itau] = $this->accounts();
        $c = Volt::test('banking.transactions.statement-import')->set('accountId', $cef->id)
            ->set('file', UploadedFile::fake()->createWithContent('cef.csv', $this->csv()))->call('analyzeFile');
        $c->set('rows.2.selected', true)->set('rows.2.type', 'transfer')->set('rows.2.transfer_account_id', $itau->id)->assertSee('de Itaú');
        foreach ([0, 1, 3] as $k) {
            $c->set("rows.$k.selected", false);
        }
        $c->call('confirmImport')->assertHasNoErrors();
        $in = Transaction::where('bank_account_id', $cef->id)->firstOrFail();
        $this->assertSame(['transfer', 131094.09], [$in->type, (float) $in->amount]);
        $this->assertEqualsWithDelta(-131094.09, (float) $in->related->amount, 0.001);
    }

    public function test_ofx_goes_to_same_review(): void
    {
        $this->login();
        [$cef] = $this->accounts();
        $ofx = "OFXHEADER:100\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>"
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260920<TRNAMT>-12.50<FITID>A1<NAME>PADARIA</STMTTRN>"
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260922<TRNAMT>-50000.00<FITID>A2<NAME>PIX Cassio Mota E Silva</STMTTRN>"
            ."</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
        $c = Volt::test('banking.transactions.statement-import')->set('accountId', $cef->id)
            ->set('file', UploadedFile::fake()->createWithContent('extrato.ofx', $ofx))->call('analyzeFile')->assertHasNoErrors();
        $this->assertSame('OFX', $c->get('source'));
        $this->assertSame(['expense', 'transfer'], array_column($c->get('rows'), 'type'));
        $this->assertSame('A1', $c->get('rows')[0]['fitid']);
    }
}
