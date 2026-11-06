<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TransactionAccountCardFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedLedger(): array
    {
        $this->actingAs(User::factory()->create());

        $bb = BankAccount::create(['name' => 'Banco do Brasil', 'type' => 'checking', 'initial_balance' => 0, 'status' => true]);
        $xp = BankAccount::create(['name' => 'XP Investimentos', 'type' => 'investment', 'initial_balance' => 0, 'status' => true]);
        $fund = BankAccount::create(['name' => 'BB Fundo', 'type' => 'fund', 'initial_balance' => 0, 'status' => true]);
        $cash = BankAccount::create(['name' => 'Dinheiro', 'type' => 'cash', 'initial_balance' => 0, 'status' => true]);
        $repasse = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'status' => true]);
        $card = CreditCard::create([
            'name' => 'Nubank',
            'brand' => 'Mastercard',
            'limit' => 5000,
            'closing_day' => 10,
            'due_day' => 17,
            'status' => true,
        ]);

        $mk = function (array $attrs) {
            return Transaction::create(array_merge([
                'type' => 'expense',
                'amount' => '10.00',
                'description' => 'Lançamento',
                'date' => '2026-11-05',
                'status' => 'settled',
            ], $attrs));
        };

        $tBb = $mk(['description' => 'Gasto BB', 'bank_account_id' => $bb->id]);
        $tXp = $mk(['description' => 'Gasto XP', 'bank_account_id' => $xp->id]);
        $tFund = $mk(['description' => 'Gasto Fundo', 'bank_account_id' => $fund->id]);
        $tCash = $mk(['description' => 'Gasto Dinheiro', 'bank_account_id' => $cash->id]);
        $tRep = $mk(['description' => 'Gasto Repasse', 'bank_account_id' => $repasse->id, 'type' => 'income', 'amount' => '50.00']);
        $tCard = $mk(['description' => 'Gasto Cartão Nubank', 'credit_card_id' => $card->id, 'bank_account_id' => null]);

        return compact('bb', 'xp', 'fund', 'cash', 'repasse', 'card', 'tBb', 'tXp', 'tFund', 'tCash', 'tRep', 'tCard');
    }

    private function listComponent(array $ledger)
    {
        return Volt::test('banking.transactions.index')
            ->set('from', '2026-11-01')
            ->set('to', '2026-11-30');
    }

    public function test_todas_mostra_contas_e_cartoes_e_pills_curtas(): void
    {
        $ledger = $this->seedLedger();
        $c = $this->listComponent($ledger);

        $c->assertSee('Tipo de conta ou cartão')
            ->assertSee('Conta ou cartão')
            ->assertSeeHtml('data-test="account-type-all"')
            ->assertSeeHtml('data-test="account-type-checking"')
            ->assertSeeHtml('data-test="account-type-investment"')
            ->assertSeeHtml('data-test="account-type-fund"')
            ->assertSeeHtml('data-test="account-type-cash"')
            ->assertSeeHtml('data-test="account-type-repasse"')
            ->assertSeeHtml('data-test="account-type-card"')
            ->assertSee('Gasto BB')
            ->assertSee('Gasto Cartão Nubank')
            ->assertSee('Gasto XP');
    }

    public function test_tipo_corrente_filtra_contas_e_dropdown(): void
    {
        $ledger = $this->seedLedger();
        $c = $this->listComponent($ledger)->set('accountType', 'checking');

        $c->assertSee('Gasto BB')
            ->assertDontSee('Gasto XP')
            ->assertDontSee('Gasto Cartão Nubank')
            ->assertSeeHtml('value="'.$ledger['bb']->id.'"')
            ->assertDontSeeHtml('value="'.$ledger['xp']->id.'"')
            ->assertDontSeeHtml('value="card:'.$ledger['card']->id.'"');
    }

    public function test_tipo_cartao_filtra_todos_e_cartao_especifico(): void
    {
        $ledger = $this->seedLedger();
        $other = CreditCard::create([
            'name' => 'BTG 0052',
            'limit' => 1000,
            'closing_day' => 5,
            'due_day' => 12,
            'status' => true,
        ]);
        Transaction::create([
            'type' => 'expense',
            'amount' => '20.00',
            'description' => 'Gasto Cartão BTG',
            'date' => '2026-11-05',
            'status' => 'settled',
            'credit_card_id' => $other->id,
        ]);

        $c = $this->listComponent($ledger)->set('accountType', 'card');
        $c->assertSee('Gasto Cartão Nubank')
            ->assertSee('Gasto Cartão BTG')
            ->assertDontSee('Gasto BB')
            ->assertSeeHtml('value="card:'.$ledger['card']->id.'"')
            ->assertSeeHtml('value="card:'.$other->id.'"');

        $c->set('account', 'card:'.$ledger['card']->id)
            ->assertSee('Gasto Cartão Nubank')
            ->assertDontSee('Gasto Cartão BTG')
            ->assertDontSee('Gasto BB');
    }

    public function test_conta_especifica_e_card_prefix_em_todas(): void
    {
        $ledger = $this->seedLedger();
        $c = $this->listComponent($ledger);

        $c->set('account', (string) $ledger['bb']->id)
            ->assertSee('Gasto BB')
            ->assertDontSee('Gasto XP')
            ->assertDontSee('Gasto Cartão Nubank');

        $c->set('account', 'card:'.$ledger['card']->id)
            ->assertSee('Gasto Cartão Nubank')
            ->assertDontSee('Gasto BB');
    }

    public function test_troca_de_tipo_limpa_selecao_incompativel_e_clear_reseta(): void
    {
        $ledger = $this->seedLedger();
        $c = $this->listComponent($ledger)
            ->set('accountType', 'checking')
            ->set('account', (string) $ledger['bb']->id)
            ->assertSet('account', (string) $ledger['bb']->id);

        $c->set('accountType', 'investment')
            ->assertSet('account', '')
            ->assertSee('Gasto XP')
            ->assertDontSee('Gasto BB');

        $c->set('accountType', 'card')
            ->set('account', 'card:'.$ledger['card']->id)
            ->set('accountType', 'checking')
            ->assertSet('account', '')
            ->assertSee('Gasto BB')
            ->assertDontSee('Gasto Cartão Nubank');

        $c->set('accountType', 'fund')
            ->set('account', (string) $ledger['fund']->id)
            ->call('clearFilters')
            ->assertSet('accountType', '')
            ->assertSet('account', '');
    }

    public function test_account_type_invalido_volta_para_todas(): void
    {
        $ledger = $this->seedLedger();
        $this->listComponent($ledger)
            ->set('accountType', 'bogus')
            ->assertSet('accountType', '');
    }
}
