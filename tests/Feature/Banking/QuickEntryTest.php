<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Models\User;
use App\Support\Access;
use App\Support\QuickEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Lançamento rápido global: mesmo componente/validação de Lançamentos, em modo só-modal. */
class QuickEntryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(Access::MODULES)])->save();

        return $u;
    }

    private function userWith(array $modules, string $role = 'operator'): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => false, 'status' => 'active', 'modules' => $modules, 'role' => $role])->save();

        return $u;
    }

    public function test_layout_has_launcher_fab_and_quick_component_for_finance_users(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('writs.kanban'))->assertOk()->getContent();

        $this->assertStringContainsString('data-quick-launch', $html);
        $this->assertStringContainsString('data-quick-fab', $html);
        $this->assertStringContainsString('data-quick-entry', $html);
        $this->assertStringContainsString('has-quick-fab', $html);
        // Em Requisitórios o "+" e o "+ Lançar" oferecem também a ação do módulo.
        $this->assertStringContainsString('Novo requisitório', $html);
        $this->assertStringContainsString(route('writs.create'), $html);
    }

    public function test_dashboard_opens_entry_directly_without_module_menu(): void
    {
        $this->actingAs($this->admin());
        Livewire::withoutLazyLoading();
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-quick-fab', $html);
        $this->assertStringNotContainsString('data-quick-module-action', $html);
        $this->assertNull(QuickEntry::moduleAction('dashboard', auth()->user()));
        $this->assertNull(QuickEntry::moduleAction('banking.dashboard', auth()->user()));
        $this->assertSame('Registrar movimentação', QuickEntry::moduleAction('investments.dashboard', auth()->user())['label']);
    }

    public function test_hidden_for_users_without_finance_or_read_only(): void
    {
        $this->actingAs($this->userWith(['requisitorio']));
        $html = $this->get(route('writs.kanban'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-quick-launch', $html);
        $this->assertStringNotContainsString('data-quick-fab', $html);
        $this->assertStringNotContainsString('data-quick-entry', $html);

        $this->assertFalse(QuickEntry::enabled($this->userWith(['financeiro'], 'viewer')));
        $this->assertTrue(QuickEntry::enabled($this->userWith(['financeiro'])));
        $this->assertFalse(QuickEntry::enabled(null));
    }

    public function test_transactions_page_does_not_mount_a_second_instance(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('banking.transactions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-quick-launch', $html);
        $this->assertStringNotContainsString('data-quick-entry', $html);
    }

    public function test_quick_component_opens_by_type_with_tabs(): void
    {
        $this->actingAs($this->admin());

        $c = Volt::test('banking.transactions.index', ['quick' => true])
            ->assertSet('showFormModal', false)
            ->assertDontSeeHtml('data-statement-import-link');

        foreach (['expense' => 'Nova despesa', 'income' => 'Nova receita', 'transfer' => 'Nova transferência'] as $type => $title) {
            $c->dispatch('quick-entry', type: $type)
                ->assertSet('showFormModal', true)
                ->assertSet('formType', $type)
                ->assertSee($title)
                ->assertSeeHtml('data-quick-tabs')
                ->assertSeeHtml('data-quick-tab="'.$type.'"')
                ->assertSee('Salvar e lançar outro')
                ->call('cancel');
        }
    }

    public function test_tabs_switch_type_keeping_typed_values(): void
    {
        $this->actingAs($this->admin());
        $acc = BankAccount::create(['name' => 'Conta', 'initial_balance' => 0]);

        Volt::test('banking.transactions.index', ['quick' => true])
            ->dispatch('quick-entry', type: 'expense')
            ->set('formAmount', '12,34')
            ->set('formDescription', 'Mudei de ideia')
            ->set('formBankAccountId', $acc->id)
            ->call('switchType', 'income')
            ->assertSet('formType', 'income')
            ->assertSet('formDescription', 'Mudei de ideia')
            ->assertSet('formBankAccountId', $acc->id)
            ->assertSet('formCategoryId', null)
            ->call('switchType', 'card_expense')
            ->assertSet('formType', 'expense')
            ->assertSet('formIsCard', true)
            ->call('switchType', 'transfer')
            ->assertSet('formIsCard', false)
            ->assertSet('formType', 'transfer');
    }

    public function test_quick_save_uses_same_logic_and_notifies_page(): void
    {
        $this->actingAs($this->admin());
        $acc = BankAccount::create(['name' => 'Conta', 'initial_balance' => 0]);
        $cat = Category::create(['name' => 'Escritório', 'type' => 'expense', 'status' => true]);

        Volt::test('banking.transactions.index', ['quick' => true])
            ->dispatch('quick-entry', type: 'expense')
            ->set('formAmount', '50.00')
            ->set('formDescription', 'Papelaria')
            ->set('formBankAccountId', $acc->id)
            ->set('formCategoryId', $cat->id)
            ->call('saveTransaction')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false)
            ->assertDispatched('transaction-saved')
            ->assertDispatched('toast');

        $this->assertDatabaseHas('transactions', ['description' => 'Papelaria', 'type' => 'expense', 'bank_account_id' => $acc->id]);
        $this->assertFalse(session()->has('status'), 'No modo rápido o aviso é toast, não flash para a próxima página.');
    }

    public function test_quick_save_validates_like_transactions_page(): void
    {
        $this->actingAs($this->admin());

        Volt::test('banking.transactions.index', ['quick' => true])
            ->dispatch('quick-entry', type: 'expense')
            ->call('saveTransaction')
            ->assertHasErrors(['formAmount'])
            ->assertSet('showFormModal', true);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_save_and_create_another_keeps_type_and_account(): void
    {
        $this->actingAs($this->admin());
        $a = BankAccount::create(['name' => 'Origem', 'initial_balance' => 100]);
        $b = BankAccount::create(['name' => 'Destino', 'initial_balance' => 0]);
        $cat = Category::create(['name' => 'Honorários', 'type' => 'income', 'status' => true]);

        Volt::test('banking.transactions.index', ['quick' => true])
            ->dispatch('quick-entry', type: 'income')
            ->set('formAmount', '10.00')
            ->set('formDescription', 'Primeira')
            ->set('formBankAccountId', $a->id)
            ->set('formCategoryId', $cat->id)
            ->call('saveAndCreateAnother')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', true)
            ->assertSet('formType', 'income')
            ->assertSet('formBankAccountId', $a->id)
            ->assertSet('formAmount', '')
            ->assertSet('formDescription', '')
            ->call('switchType', 'transfer')
            ->set('formAmount', '5.00')
            ->set('formTransferToId', $b->id)
            ->call('saveAndCreateAnother')
            ->assertHasNoErrors()
            ->assertSet('formType', 'transfer')
            ->assertSet('formBankAccountId', $a->id);

        $this->assertSame(1, Transaction::where('description', 'Primeira')->count());
        $out = Transaction::where('type', 'transfer')->where('bank_account_id', $a->id)->first();
        $this->assertNotNull($out);
        $this->assertLessThan(0, (float) $out->amount, 'Transferência usa o TransferService (saída negativa na origem).');
    }

    public function test_transactions_page_itself_answers_quick_entry_event(): void
    {
        $this->actingAs($this->admin());

        Volt::test('banking.transactions.index')
            ->dispatch('quick-entry', type: 'transfer')
            ->assertSet('showFormModal', true)
            ->assertSet('formType', 'transfer');
    }

    public function test_dashboard_and_finance_overview_have_shortcuts_and_listen(): void
    {
        $this->actingAs($this->admin());
        Livewire::withoutLazyLoading();

        Volt::test('dashboard.index')
            ->assertSeeHtml('data-quick-shortcuts')
            ->assertSeeHtml('data-quick-shortcut="expense"')
            ->assertSeeHtml('data-quick-shortcut="income"')
            ->assertSeeHtml('data-quick-shortcut="transfer"')
            ->assertSee('Editar painel')
            ->assertDontSee('+ Novo')
            ->call('toggleCustomize')
            ->assertSet('customizing', true)
            ->dispatch('transaction-saved')
            ->assertOk();

        Livewire::withoutLazyLoading();
        Volt::test('banking.dashboard')
            ->assertSeeHtml('data-quick-shortcuts')
            ->dispatch('transaction-saved')
            ->assertOk();
    }
}
