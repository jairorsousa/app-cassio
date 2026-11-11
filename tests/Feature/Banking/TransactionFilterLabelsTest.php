<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Linha de filtros de Lançamentos: rótulos claros, selects que não cortam o texto e a mesma lógica de antes. */
class TransactionFilterLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): array
    {
        $this->actingAs(User::factory()->create());
        $bb = BankAccount::create(['name' => 'Banco do Brasil', 'type' => 'checking', 'initial_balance' => 0, 'initial_balance_date' => '2026-10-01', 'status' => true]);
        $card = CreditCard::create(['name' => 'Visa', 'closing_day' => 5, 'due_day' => 15, 'status' => true, 'holder_type' => 'luciane']);
        $heleno = Contact::create(['name' => 'Heleno Mota', 'status' => true]);
        $mk = fn (array $a) => Transaction::create(array_merge(['type' => 'expense', 'amount' => '10.00', 'status' => 'settled', 'date' => '2026-11-05'], $a));

        return [
            'old' => $mk(['description' => 'Gasto antigo', 'date' => '2026-09-20', 'bank_account_id' => $bb->id]),
            'new' => $mk(['description' => 'Gasto novo', 'bank_account_id' => $bb->id]),
            'card' => $mk(['description' => 'Compra Luciane', 'credit_card_id' => $card->id, 'status' => 'pending']),
            'linked' => $mk(['description' => 'Gasto do Heleno', 'bank_account_id' => $bb->id, 'link_contact_id' => $heleno->id]),
            'heleno' => $heleno,
        ];
    }

    private function list()
    {
        return Volt::test('banking.transactions.index')->set('from', '2026-09-01')->set('to', '2026-11-30');
    }

    public function test_labels_options_and_help_are_clear(): void
    {
        $l = $this->ledger();
        $html = $this->list()->html();

        foreach (['Lançamentos antigos (antes de 01/10/2026)', 'Mostrar (junto com os demais)', 'Ocultar os antigos', 'Só os antigos',
            'Titular do cartão', 'Todos (contas e cartões)', '>Luciane</option>',
            'Vínculo', 'Todos (com ou sem vínculo)', 'Só os que têm algum vínculo', '<optgroup label="Pessoas">', 'Pessoa: Heleno Mota',
            'Incluir lançamentos de teste', 'Limpar filtros', 'data-help-tip'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['>Históricos', '>Mostrar</option>', 'Todos os titulares', 'Todos os vínculos', 'Somente históricos', 'Cartão: Luciane'] as $old) {
            $this->assertStringNotContainsString($old, $html, $old);
        }
        // Os três selects têm rótulo visível ligado a eles e altura que comporta o texto (sem padding vertical).
        foreach (['historico', 'titular', 'vinculo'] as $f) {
            $this->assertMatchesRegularExpression('/<label for="transaction-'.$f.'-filter"/', $html);
            $this->assertMatchesRegularExpression('/<select id="transaction-'.$f.'-filter"[^>]*class="h-\[48px\][^"]*py-0[^"]*leading-5/', $html);
        }
        $this->assertSame(4, substr_count($html, 'data-help-tip'));
        $this->assertStringNotContainsString('h-[32px]', $html);
    }

    public function test_filter_logic_is_unchanged(): void
    {
        $l = $this->ledger();

        $this->list()->set('historico', '')->assertSee('Gasto antigo')->assertSee('Gasto novo');
        $this->list()->set('historico', 'ocultar')->assertDontSee('Gasto antigo')->assertSee('Gasto novo');
        $this->list()->set('historico', 'somente')->assertSee('Gasto antigo')->assertDontSee('Gasto novo');

        $this->list()->set('titular', 'luciane')->assertSee('Compra Luciane')->assertDontSee('Gasto novo');

        $this->list()->set('vinculo', 'com')->assertSee('Gasto do Heleno')->assertDontSee('Gasto novo');
        $this->list()->set('vinculo', 'contact:'.$l['heleno']->id)->assertSee('Gasto do Heleno')->assertDontSee('Compra Luciane');

        $this->list()->set('historico', 'ocultar')->set('vinculo', 'com')->set('titular', 'luciane')->call('clearFilters')
            ->assertSet('historico', '')->assertSet('vinculo', '')->assertSet('testes', '');
    }

    public function test_other_selects_no_longer_clip_text(): void
    {
        $this->ledger();
        $this->get(route('banking.reports.cashflow'))->assertOk()->assertSee('h-[40px] py-0 pl-3 pr-10 leading-5', false);
        $this->get(route('investments.b3'))->assertOk()->assertSee('h-10 py-0 pl-2 pr-10 leading-5', false);
        $this->get(route('settings'))->assertOk()->assertSee('<select wire:model="text_size" class="h-10 py-0 pl-2 pr-10 leading-5', false);
    }
}
