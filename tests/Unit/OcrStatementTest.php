<?php

namespace Tests\Unit;

use App\Domains\Banking\Services\Statements\GenericStatementReader;
use Tests\TestCase;

class OcrStatementTest extends TestCase
{
    /** Texto real do OCR do extrato Caixa (com ruídos: "5.16/7,18D", "50.000,/00 D", "Cº", saldo mal lido). */
    private const CAIXA = <<<'TXT'
SALDO ANTERIOR R$ 66.888,02 C
La nçamentos Nr.Doc — Histórico/Complemento Favorecido CPF/CNP]J Valor Saldo
20/10/2026 - 00:00:00 000000 SALDO DIA 0,00 C 99.138,53 C
20/10/2026 - 18:01:55 201801 DEB PIX CHAVE Mota E Silva Migno **604.748/0*** 8.629,72D  /99.138,53C
19/10/2026 - 00:00:00 000000 — SALDO DIA 0,00 C 107.768,25 C
17/10/2026 - 06:08:02 170608 — DEBPIXCHAVE Cassio Mota E Silv **+* 394,813*** 5.16/7,18D  107./68,25C
16/10/2026 - 00:00:00 000000 SALDO DIA 0,00 C 112.935,43 C
16/10/2026 - 12:01:03 161201 DEB PIX CHAVE Cassio Mota E Silv *** 394.813*** 50.000,/00 D 112.935,43C
13/10/2026 - 00:00:00 000000 SALDO DIA 0,00 C 162.935,43 C
10/10/2026 - 22:19:02 102219 DEB PIX CHAVE Mota E Silva Migno **604.748/0*** 3.485,95 D 162.935,43 C
10/10/2026 - 22:16:17 102216 DEB PIX CHAVE Mota E Silva Migno **604.748/0*** 2.322,55 D 166.421,38 C
10/10/2026 - 20:27:27 102027 DEB PIX CHAVE Cassio Mota E Silv *** 3094.813*** 21.126,95D 168.743,93C
08/10/2026 - 00:00:00 000000 — SALDO DIA 0,00 C 189.870,88 C
08/10/2026 - 17:08:25 003151 — CRED LEVANT JUDICIAL 78.810,26 Cº 18.980,88 C
08/10/2026 - 17:04:58 003151 — CRED LEVANT JUDICIAL 13.991,24 Cº 111.060,62 C
08/10/2026 - 17:01:38 003151 CRED LEVANT JUDICIAL 30.181,36 C 97.069,38 C
TXT;

    public function test_extrato_caixa_por_ocr(): void
    {
        $r = app(GenericStatementReader::class)->fromOcrText(self::CAIXA);
        $amounts = array_column($r['rows'], 'amount');
        $this->assertSame([30181.36, 13991.24, 78810.26, -21126.95, -2322.55, -3485.95, -50000.0, -5167.18, -8629.72], $amounts);
        $this->assertSame(5, $r['ignored_balance_lines']);
        $this->assertSame(66888.02, $r['opening_balance']);
        $this->assertEqualsWithDelta(122982.86, array_sum(array_filter($amounts, fn ($a) => $a > 0)), 0.001);
        $this->assertEqualsWithDelta(-90732.35, array_sum(array_filter($amounts, fn ($a) => $a < 0)), 0.001);
        $this->assertSame('2026-10-08', $r['rows'][0]['date']);
        $this->assertCount(1, $r['warnings']); // só o aviso geral: o saldo corrido fecha em 99.138,53
    }

    public function test_valor_ocr_usa_ultimos_dois_digitos_como_centavos(): void
    {
        $this->assertSame(5167.18, GenericStatementReader::ocrMoney('5,167,18D'));
        $this->assertSame(50000.0, GenericStatementReader::ocrMoney('50.000,/00 D'));
        $this->assertSame(3485.95, GenericStatementReader::ocrMoney('3,.485,95 D'));
    }

    public function test_extrato_caixa_html_iso_8859_1(): void
    {
        $tr = fn ($d, $doc, $h, $v, $s) => "<tr class=\"impressao_salvar\"><td>$d</td><td>$doc</td><td>$h</td><td>$v</td><td>$s</td></tr>";
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"></head><body><table>'
            .'<tr><td>Data Mov.</td><td>Nr. Doc.</td><td>Hist'.chr(0xF3).'rico</td><td>Valor</td><td>Saldo</td></tr>'
            .$tr('', '000000', 'SALDO ANTERIOR', '0,00', '66.888,02 C')
            .$tr('08/10/2026', '003151', 'CR LEV JUD', '30.181,36 C', '97.069,38 C')
            .$tr('08/10/2026', '000000', 'SALDO DIA', '0,00 C', '97.069,38 C')
            .$tr('13/10/2026', '102027', 'DEB PIX CH', '21.126,95 D', '75.942,43 C')
            .'</table></body></html>';
        $this->assertTrue(GenericStatementReader::looksLikeHtml($html));
        $r = app(GenericStatementReader::class)->fromHtml($html);
        $this->assertSame([['2026-10-08', 30181.36, 97069.38], ['2026-10-13', -21126.95, 75942.43]],
            array_map(fn ($x) => [$x['date'], $x['amount'], $x['balance']], $r['rows']));
        $this->assertSame(66888.02, $r['opening_balance']);
        $this->assertSame(2, $r['ignored_balance_lines']);
    }
}
