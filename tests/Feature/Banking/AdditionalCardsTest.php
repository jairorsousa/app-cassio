<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\CardHolder;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\InstallmentService;
use App\Domains\Banking\Services\Statements\GenericStatementReader;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdditionalCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(array $a = []): CreditCard
    {
        return CreditCard::create(array_merge(['name' => 'BTG 0052', 'last_four' => '0052', 'limit' => 100000, 'closing_day' => 15, 'due_day' => 19, 'status' => true], $a));
    }

    public function test_compra_no_adicional_vai_para_fatura_do_principal_e_filtra_por_titular(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        $lu = CardHolder::create(['name' => 'Luciane']);
        $me = CardHolder::firstOrCreate(['name' => 'Cássio']);
        $main = $this->card(['card_holder_id' => $me->id]);
        $add = $this->card(['name' => 'BTG Luciane 2043', 'last_four' => '2043', 'card_type' => 'adicional', 'parent_card_id' => $main->id, 'card_holder_id' => $lu->id]);
        $virt = $this->card(['name' => 'BTG virtual', 'last_four' => '0052', 'card_type' => 'virtual', 'parent_card_id' => $main->id]);
        $this->assertSame('titular:'.$me->id, $virt->holderKey()); // virtual herda o titular do principal
        $this->assertSame('Cássio', $virt->holderLabel());

        [$t] = app(InstallmentService::class)->split($add, Carbon::parse('2026-05-10'), 50, 1, 'Farmácia Lu');
        [$t2] = app(InstallmentService::class)->split($main, Carbon::parse('2026-05-10'), 70, 1, 'Posto');

        $this->assertSame($main->id, $t->credit_card_id);
        $this->assertSame($add->id, $t->additional_card_id);
        $this->assertSame(1, $main->invoices()->count());
        $this->assertSame(0, $add->invoices()->count());
        $this->assertSame($t->credit_card_invoice_id, $t2->credit_card_invoice_id);
        $this->assertEquals(120, (float) Transaction::where('credit_card_invoice_id', $t->credit_card_invoice_id)->sum('amount'));

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Volt::test('banking.transactions.index')->set('titular', 'titular:'.$lu->id)->assertSee('Farmácia Lu')->assertDontSee('Posto');
        Volt::test('banking.transactions.index')->set('titular', 'titular:'.$me->id)->assertSee('Posto')->assertDontSee('Farmácia Lu');
    }

    public function test_formulario_adicional_herda_do_principal(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $main = $this->card();
        Volt::test('banking.cards.index')->call('create')->set('name', 'BTG Isabela')->set('last_four', '49')->call('save')->assertHasErrors(['last_four'])
            ->set('last_four', '4920')->set('card_type', 'adicional')->set('parent_card_id', $main->id)->set('limit', '5')->call('save')->assertHasNoErrors();
        $isa = CreditCard::where('name', 'BTG Isabela')->firstOrFail();
        $this->assertSame('4920', $isa->last_four);
        $this->assertSame($main->id, $isa->parent_card_id);
        $this->assertEquals(100000, (float) $isa->limit);
        $this->assertSame(19, $isa->due_day);
        // Principal com adicional não pode virar adicional.
        $other = $this->card(['name' => 'Outro', 'last_four' => '1111']);
        Volt::test('banking.cards.index')->call('edit', $main->id)->set('card_type', 'adicional')->set('parent_card_id', $other->id)->call('save')->assertHasErrors(['parent_card_id']);
    }

    public function test_planilha_com_final_cartao_em_secoes(): void
    {
        $raw = [
            ['', 'Resumo', '', '', '', 'Total da Fatura', '', '100.00'],
            ['', 'Saldo fatura anterior e pagamentos', '', '', '', '', '', ''],
            ['', 'Data', 'Descrição', '', 'Valor', 'Código de autorização', 'Final Cartão', ''],
            ['', '13/04/2026', 'Cancelamento - Loja', '', '-10.00', 'X1', '0052', ''],
            ['', 'Data', 'Descrição', '', 'Valor', 'Tipo de compra', 'Código de autorização', 'Final Cartão'],
            ['', '14/05/2026', 'Restaurante', '', '80.00', 'Compra à vista', 'A1', '0052'],
            ['', '14/05/2026', 'Farmácia', '', '30.00', 'Compra à vista', 'A2', '2043'],
        ];
        $r = app(GenericStatementReader::class)->fromTable($raw, 'XLSX');
        $this->assertSame(['0052', '0052', '2043'], array_column($r['rows'], 'final'));
        $this->assertSame(100.0, $r['summary']['invoice_total']);
    }

    public function test_revisao_pergunta_final_desconhecido_e_memoriza_sem_gravar_fatura(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $main = $this->card();
        $isa = $this->card(['name' => 'BTG Isabela 4920', 'last_four' => '4920', 'card_type' => 'adicional', 'parent_card_id' => $main->id]);
        $luOld = $this->card(['name' => 'BTG Luciane 2043', 'last_four' => '2043']);
        $csv = "Data;Descrição;Valor;Final Cartão\n14/05/2026;Restaurante;80,00;0052\n14/05/2026;Livraria;20,00;4920\n14/05/2026;Farmácia;30,00;2043\n15/05/2026;Padaria;5,00;7777\n";
        $file = UploadedFile::fake()->createWithContent('fatura.csv', $csv);

        $c = Volt::test('banking.transactions.statement-import')->set('target', 'card')->set('cardId', $main->id)->set('invoiceMonth', '2026-05')
            ->set('file', $file)->call('analyzeFile')->assertHasNoErrors();
        $finals = $c->get('finals');
        $this->assertSame('principal', $finals['0052']['state']);
        $this->assertSame('additional', $finals['4920']['state']);
        $this->assertSame('other', $finals['2043']['state']);
        $this->assertSame('unknown', $finals['7777']['state']);

        $c->call('confirmImport')->assertHasErrors(['rows']); // pendências: não grava
        $c->set('finals.2043.choice', 'card:'.$luOld->id)->call('assignFinal', '2043')
            ->set('finals.7777.choice', 'new')->set('finals.7777.new_name', 'BTG Novo 7777')->call('assignFinal', '7777');
        $finals = $c->get('finals');
        $this->assertSame('additional', $finals['2043']['state']);
        $this->assertSame('additional', $finals['7777']['state']);
        $this->assertSame([$main->id, 'adicional'], [$luOld->fresh()->parent_card_id, $luOld->fresh()->card_type]);
        $new = CreditCard::where('name', 'BTG Novo 7777')->firstOrFail();
        $this->assertSame(['7777', $main->id, 19], [$new->last_four, $new->parent_card_id, $new->due_day]);
        $rows = collect($c->get('rows'))->keyBy('card_final');
        $this->assertSame($isa->id, $rows['4920']['additional_card_id']);
        $this->assertNull($rows['0052']['additional_card_id']);
        $this->assertSame(0, Transaction::count());
    }

    public function test_fatura_redireciona_vinculado_e_filtra_por_cartao_e_titular(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $me = CardHolder::firstOrCreate(['name' => 'Cássio']);
        $isa = CardHolder::create(['name' => 'Isabela']);
        $main = $this->card(['name' => 'BTG 8292', 'last_four' => '8292', 'card_holder_id' => $me->id]);
        $virt = $this->card(['name' => 'BTG 0052', 'last_four' => '0052', 'card_type' => 'virtual', 'parent_card_id' => $main->id]);
        $add = $this->card(['name' => 'BTG Isabela 4920', 'last_four' => '4920', 'card_type' => 'adicional', 'parent_card_id' => $main->id, 'card_holder_id' => $isa->id]);
        [$t] = app(InstallmentService::class)->split($virt, Carbon::parse('2026-05-10'), 70, 1, 'Posto Virtual');
        app(InstallmentService::class)->split($add, Carbon::parse('2026-05-10'), 30, 1, 'Livraria Isa');

        $this->get(route('banking.cards.invoices', $add))->assertRedirect(route('banking.cards.invoices', ['card' => $main->id, 'cardFilter' => $add->id]));
        Volt::test('banking.cards.invoices', ['card' => $main])->call('toggle', $t->credit_card_invoice_id)
            ->assertSee('Posto Virtual')->assertSee('Livraria Isa')
            ->set('cardFilter', (string) $add->id)->assertSee('Livraria Isa')->assertDontSee('Posto Virtual')
            ->set('cardFilter', '')->set('holderFilter', 'titular:'.$me->id)->assertSee('Posto Virtual')->assertDontSee('Livraria Isa');
    }
}
