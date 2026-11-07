<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\OfxImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OfxCaixaFormatTest extends TestCase
{
    use RefreshDatabase;

    private function tx(string $type, string $date, string $amt, string $fitid, string $memo): string
    {
        return "     <STMTTRN>\n      <TRNTYPE>{$type}</TRNTYPE>\n      <DTPOSTED>{$date}00[-3:BRT]</DTPOSTED>\n      <TRNAMT>{$amt}</TRNAMT>\n      <FITID>{$fitid}</FITID>\n      <CHECKNUM>{$fitid}</CHECKNUM>\n      <MEMO>{$memo}</MEMO>\n     </STMTTRN>\n";
    }

    private function caixa(): string
    {
        return "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nSECURITY:NONE\nENCODING:USASCII\nCHARSET:1252\nCOMPRESSION:NONE\nOLDFILEUID:NONE\nNEWFILEUID:NONE\n\n<OFX>\n <BANKMSGSRSV1>\n  <STMTTRNRS>\n   <STMTRS>\n    <CURDEF>BRL</CURDEF>\n    <BANKACCTFROM>\n     <BANKID>0104</BANKID>\n     <ACCTID>0005820362329</ACCTID>\n    </BANKACCTFROM>\n    <BANKTRANLIST>\n"
            .$this->tx('DEBIT', '20261016', '-50.000.00', '161201', 'DEB PIX CHAVE')
            .$this->tx('DEBIT', '20261013', '-3.485.95', '102219', 'DEB PIX CHAVE')
            .$this->tx('CREDIT', '20261008', '78.810.26', '003151', 'CRED LEVANT JUDICIAL')
            .$this->tx('CREDIT', '20261008', '13.991.24', '003151', 'CRED LEVANT JUDICIAL')
            .$this->tx('CREDIT', '20261008', '30.181.36', '003151', 'CRED LEVANT JUDICIAL')
            ."    </BANKTRANLIST>\n    <LEDGERBAL>\n     <BALAMT>66.888.02</BALAMT>\n    </LEDGERBAL>\n   </STMTRS>\n  </STMTTRNRS>\n </BANKMSGSRSV1>\n</OFX>";
    }

    public function test_caixa_amounts_and_repeated_fitids(): void
    {
        $p = app(OfxImportService::class)->parse($this->caixa());
        $t = $p['transactions'];
        $this->assertCount(5, $t);
        $this->assertSame(['50000.00', '3485.95', '78810.26', '13991.24', '30181.36'], array_column($t, 'amount'));
        $this->assertSame(['expense', 'expense', 'income', 'income', 'income'], array_column($t, 'type'));
        $this->assertSame('2026-10-16', $t[0]['date']);
        $this->assertCount(5, array_unique(array_column($t, 'fitid')));
        $this->assertSame('003151', $t[2]['fitid']);
        // mesmo arquivo = mesmos ids (reimportação detecta)
        $this->assertSame(array_column($t, 'fitid'), array_column(app(OfxImportService::class)->parse($this->caixa())['transactions'], 'fitid'));
    }

    public function test_other_amount_formats(): void
    {
        $base = "OFXHEADER:100\n<OFX><BANKTRANLIST><STMTTRN><DTPOSTED>20261001<TRNAMT>%s<FITID>X<NAME>A</STMTTRN></BANKTRANLIST></OFX>";
        foreach (['-1234.56' => '1234.56', '-1.234,56' => '1234.56', '1,234.56' => '1234.56', '-12' => '12.00', '1.234' => '1234.00', '0.5' => '0.50'] as $in => $out) {
            $this->assertSame($out, app(OfxImportService::class)->parse(sprintf($base, $in))['transactions'][0]['amount'], $in);
        }
    }

    public function test_caixa_ofx_reaches_review_without_saving(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true])->save();
        $this->actingAs($u);
        $acc = BankAccount::forceCreate(['name' => 'CEF', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $c = Volt::test('banking.transactions.statement-import')->set('accountId', $acc->id)
            ->set('file', UploadedFile::fake()->createWithContent('CEF_extr_2026-10.ofx', $this->caixa()))->call('analyzeFile')->assertHasNoErrors();
        $this->assertCount(5, $c->get('rows'));
        $this->assertSame('50.000,00', $c->get('rows')[0]['amount']);
        $this->assertSame(0, Transaction::count());
    }
}
