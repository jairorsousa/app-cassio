<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Brokers\Models\Broker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RepasseBankTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_tipo_de_conta_filtra_bancos_e_preserva_selecao_compativel(): void
    {
        $this->actingAs(User::factory()->create());
        $bb = BankAccount::create(['name' => 'Banco do Brasil', 'type' => 'checking', 'initial_balance' => 0]);
        $poup = BankAccount::create(['name' => 'Caixa Poupa', 'type' => 'savings', 'initial_balance' => 0]);
        $inv = BankAccount::create(['name' => 'XP Invest', 'type' => 'investment', 'initial_balance' => 0]);
        $fund = BankAccount::create(['name' => 'Fundo Auto', 'type' => 'fund', 'initial_balance' => 0]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse']);
        BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse']);
        $receipt = Transaction::create(['type' => 'income', 'amount' => '10000.00', 'description' => 'Recebimento', 'date' => \App\Support\BusinessDate::now()->subDay()->toDateString(), 'status' => 'settled', 'bank_account_id' => $bb->id]);
        app(ProcessReceiptService::class)->save($receipt, [
            'broker_id' => Broker::create(['name' => 'C', 'status' => true])->id, 'client_name' => 'Maria', 'fees_mode' => 'amount', 'fees' => '3000',
            'commission_mode' => 'amount', 'broker_amount' => '1000', 'office_amount' => '1000', 'expenses' => [], 'advances' => [],
        ]);
        $part = $receipt->destinations()->where('kind', 'client')->sole();

        $c = Volt::test('banking.accounts.repasses', ['accountId' => $client->id])->call('startPayment', $part->id)
            ->assertSet('bankType', '')->assertSet('bankId', $bb->id)
            ->assertSee('Tipo de conta')->assertSee('Todos os tipos')->assertSee('Poupança')->assertSee('Investimento')
            ->assertSee('Banco do Brasil')->assertSee('Caixa Poupa')->assertSee('XP Invest')->assertSee('Fundo Auto');

        $c->set('bankType', 'checking')->assertSet('bankId', $bb->id)
            ->assertSeeHtml('value="'.$bb->id.'"')->assertDontSeeHtml('value="'.$poup->id.'"')->assertDontSeeHtml('value="'.$inv->id.'"')->assertDontSeeHtml('value="'.$fund->id.'"');
        $c->set('bankType', 'savings')->assertSet('bankId', null)->assertSeeHtml('value="'.$poup->id.'"')->assertDontSeeHtml('value="'.$bb->id.'"');
        $c->set('bankId', $poup->id)->set('bankType', '')->assertSet('bankId', $poup->id);
        $c->set('bankType', 'bogus')->assertSet('bankType', '');

        $c->set('amount', '100')->call('savePayment')->assertHasNoErrors();
        $this->assertSame(-100.0, $poup->fresh()->balance());
        $this->assertSame(10000.0, $bb->fresh()->balance());
    }
}
