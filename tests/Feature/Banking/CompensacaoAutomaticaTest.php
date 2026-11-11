<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\ProcessReceiptBroker;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Services\BrokerStatementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Compensação automática de adiantamentos do corretor no Recebimento de processo (criação e edição). */
class CompensacaoAutomaticaTest extends TestCase
{
    use RefreshDatabase;

    private Broker $broker;

    private Category $category;

    private Transaction $receipt;

    /** @var array<int, BrokerAdvance> */
    private array $adv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        $this->actingAs(User::factory()->create());
        $bank = BankAccount::create(['name' => 'Dinheiro', 'initial_balance' => 0, 'status' => true]);
        BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        $this->category = Category::create(['name' => 'Honorários', 'type' => 'income', 'status' => true]);
        $this->broker = Broker::create(['name' => 'Milca Teste', 'status' => true]);
        // Fora de ordem de cadastro de propósito: a ordem é pela data.
        foreach ([['2025-11-08', 150], ['2025-10-27', 250], ['2025-10-31', 445], ['2025-11-25', 1000]] as [$date, $amount]) {
            $this->adv[$date] = BrokerAdvance::create(['broker_id' => $this->broker->id, 'kind' => 'personal', 'date' => $date, 'amount' => $amount, 'bank_account_id' => $bank->id]);
        }
        $this->receipt = Transaction::create(['type' => 'income', 'amount' => '2269.00', 'date' => '2026-10-06', 'description' => 'José Teste', 'status' => 'settled', 'bank_account_id' => $bank->id]);
    }

    private function form()
    {
        return Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $this->receipt->id]);
    }

    private function fillNew()
    {
        return $this->form()->set('form.client_name', 'José Teste')->set('form.fees', '2269.00')->set('form.office_amount', '998.36')->set('form.category_id', $this->category->id)
            ->call('addBroker')->set('form.brokers.0.broker_id', $this->broker->id)->set('form.brokers.0.mode', 'percent')->set('form.brokers.0.value', '15');
    }

    private function offsets(): array
    {
        return BrokerCommissionSettlement::orderBy('id')->get()->mapWithKeys(fn ($s) => [$s->advance->date->toDateString() => $s->amount_offset])->all();
    }

    public function test_criacao_com_compensacao_automatica_abate_do_mais_antigo_para_o_mais_novo(): void
    {
        $this->fillNew()->set('form.brokers.0.auto', true)
            ->assertSee('Compensar automaticamente com os adiantamentos')->assertSee('R$ 340,35')->assertSee('R$ 90,35')->assertSee('R$ 354,65')
            ->assertSee('a pagar ao corretor R$ 0,00')
            ->call('save')->assertHasNoErrors()->assertDispatched('process-receipt-saved');

        $this->assertSame(['2025-10-27' => '250.00', '2025-10-31' => '90.35'], $this->offsets());
        $this->assertEqualsWithDelta(0.0, $this->adv['2025-10-27']->fresh()->remainingBalance(), 0.001);
        $this->assertEqualsWithDelta(354.65, $this->adv['2025-10-31']->fresh()->remainingBalance(), 0.001);
        $this->assertEqualsWithDelta(150.0, $this->adv['2025-11-08']->fresh()->remainingBalance(), 0.001);
        $commission = BrokerCommission::where('broker_id', $this->broker->id)->sole();
        $this->assertSame('paid', $commission->status);
        $this->assertSame('340.35', ProcessReceiptBroker::sole()->compensated_amount);
        $summary = app(BrokerStatementService::class)->summary($this->broker);
        $this->assertEqualsWithDelta(0.0, $summary['commission_pending'], 0.001);
        $this->assertEqualsWithDelta(1504.65, $summary['advance_pending'], 0.001);
        $this->assertEqualsWithDelta(1504.65, $summary['saldo_devedor'], 0.001);
    }

    public function test_editar_recebimento_salvo_sem_compensacao_e_marcar_automatica_gera_as_compensacoes(): void
    {
        $this->fillNew()->call('save')->assertHasNoErrors();
        $this->assertSame([], $this->offsets(), 'Salvo sem compensação, como o lançamento real.');
        $before = app(BrokerStatementService::class)->summary($this->broker);
        $this->assertEqualsWithDelta(340.35, $before['commission_pending'], 0.001);

        // Reabre (Editar / Ver recebimento de processo): os dados salvos voltam e os adiantamentos aparecem.
        $this->form()->assertSet('form.brokers.0.broker_id', $this->broker->id)->assertSet('form.brokers.0.value', '15.00')
            ->assertSee('Adiantamento')->assertSee('27/10/2025')->assertSee('R$ 1.845,00')
            ->set('form.brokers.0.auto', true)->assertSee('R$ 90,35')
            ->call('save')->assertHasNoErrors()->assertDispatched('process-receipt-saved');

        $this->assertSame(['2025-10-27' => '250.00', '2025-10-31' => '90.35'], $this->offsets());
        $this->assertSame(1, BrokerCommission::where('broker_id', $this->broker->id)->count(), 'A edição não duplica a comissão.');
        $this->assertSame('paid', BrokerCommission::where('broker_id', $this->broker->id)->sole()->status);
        $this->assertSame('930.29', $this->receipt->fresh()->destinations()->where('kind', 'own')->sole()->amount, 'Minha receita não muda.');
        $after = app(BrokerStatementService::class)->summary($this->broker);
        $this->assertEqualsWithDelta(0.0, $after['commission_pending'], 0.001);
        $this->assertEqualsWithDelta($before['saldo_devedor'], $after['saldo_devedor'], 0.001, 'Compensar não muda o saldo devedor.');
    }

    public function test_editar_de_novo_refaz_sem_duplicar_e_mostra_o_que_ja_foi_compensado_como_disponivel(): void
    {
        $this->fillNew()->set('form.brokers.0.auto', true)->call('save')->assertHasNoErrors();
        // Reabrindo: o que este recebimento compensou volta como disponível (250 + 445 = 695 + 150 + 1000 = 1.845) e os valores aparecem preenchidos.
        $this->form()->assertSet('form.brokers.0.advances.'.$this->adv['2025-10-27']->id, '250.00')->assertSee('R$ 1.845,00')
            ->set('form.brokers.0.auto', true)->call('save')->assertHasNoErrors();
        $this->assertSame(['2025-10-27' => '250.00', '2025-10-31' => '90.35'], $this->offsets());
        // Gravar a edição sem tocar em nada mantém as compensações (manuais pré-preenchidas).
        $this->form()->call('save')->assertHasNoErrors();
        $this->assertSame(['2025-10-27' => '250.00', '2025-10-31' => '90.35'], $this->offsets());
        // Mudar a comissão na edição recalcula o abatimento automático.
        $this->form()->set('form.brokers.0.value', '40')->set('form.brokers.0.auto', true)->call('save')->assertHasNoErrors(); // 907,60
        $this->assertSame(['2025-10-27' => '250.00', '2025-10-31' => '445.00', '2025-11-08' => '150.00', '2025-11-25' => '62.60'], $this->offsets());
    }

    public function test_desmarcar_volta_para_valores_manuais(): void
    {
        $this->fillNew()->set('form.brokers.0.auto', true)->set('form.brokers.0.auto', false)
            ->set('form.brokers.0.advances.'.$this->adv['2025-11-08']->id, '100')->call('save')->assertHasNoErrors();
        $this->assertSame(['2025-11-08' => '100.00'], $this->offsets());
    }

    public function test_ver_recebimento_permite_editar_divisao_com_compensacoes_e_regrava(): void
    {
        $this->fillNew()->set('form.brokers.0.auto', true)->call('save')->assertHasNoErrors();
        Volt::test('banking.transactions.distribution', ['receiptId' => $this->receipt->id])
            ->assertSee('Adiantamento compensado')->assertSee('Editar divisão')
            ->call('editDivision')->assertSet('editing', true);
        $this->form()->set('form.brokers.0.auto', true)->call('save')->assertHasNoErrors();
        $this->assertSame(['2025-10-27' => '250.00', '2025-10-31' => '90.35'], $this->offsets());
    }

    public function test_repasse_ao_corretor_continua_bloqueando_a_edicao(): void
    {
        $this->fillNew()->call('save')->assertHasNoErrors();
        $commission = BrokerCommission::where('broker_id', $this->broker->id)->sole();
        app(\App\Domains\Brokers\Services\BrokerCommissionService::class)->payAmount($commission, 100, '2026-10-07', BankAccount::first()->id);
        Volt::test('banking.transactions.distribution', ['receiptId' => $this->receipt->id])->assertDontSee('Editar divisão');
        $this->form()->set('form.brokers.0.auto', true)->call('save')->assertHasErrors('division');
        $this->assertSame([], $this->offsets());
    }
}
