<?php

namespace Tests\Feature\Ui;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetDividend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SaveAndCreateAnotherSerialFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dividends_create_shows_cancel_save_and_save_and_new(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('investments.dividends.index')
            ->call('create')
            ->assertSee('Cancelar')
            ->assertSee('Salvar')
            ->assertSee('Salvar e novo')
            ->assertSeeHtml('data-test="save-and-create-another"');
    }

    public function test_dividends_save_and_create_another_keeps_modal_open_and_resets_after_success(): void
    {
        $this->actingAs(User::factory()->create());
        $class = AssetClass::firstOrCreate(['slug' => 'acoes'], ['name' => 'Ações']);
        $asset = Asset::create(['ticker' => 'PETR4', 'name' => 'Petrobras', 'asset_class_id' => $class->id]);

        Volt::test('investments.dividends.index')
            ->call('create')
            ->set('asset_id', $asset->id)
            ->set('payment_date', '2026-11-05')
            ->set('type', 'dividend')
            ->set('quantity', '10')
            ->set('unit_amount', '1.50')
            ->call('saveAndCreateAnother')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', true)
            ->assertSet('editingId', null)
            ->assertSet('asset_id', null)
            ->assertSet('quantity', '')
            ->assertSet('unit_amount', '')
            ->assertSet('formSuccessMessage', 'Provento salvo.')
            ->assertSeeHtml('data-test="save-and-create-another"');

        $this->assertDatabaseCount('asset_dividends', 1);
        $this->assertDatabaseHas('asset_dividends', [
            'asset_id' => $asset->id,
            'total' => '15.00',
        ]);
    }

    public function test_dividends_edit_hides_save_and_new(): void
    {
        $this->actingAs(User::factory()->create());
        $class = AssetClass::firstOrCreate(['slug' => 'acoes'], ['name' => 'Ações']);
        $asset = Asset::create(['ticker' => 'VALE3', 'name' => 'Vale', 'asset_class_id' => $class->id]);
        $div = AssetDividend::create([
            'asset_id' => $asset->id,
            'payment_date' => '2026-11-01',
            'type' => 'dividend',
            'unit_amount' => 2,
            'quantity' => 5,
            'total' => 10,
        ]);

        Volt::test('investments.dividends.index')
            ->call('edit', $div->id)
            ->assertSee('Editar provento')
            ->assertDontSeeHtml('data-test="save-and-create-another"');
    }

    public function test_operations_create_form_shows_save_and_new(): void
    {
        $this->actingAs(User::factory()->create());
        BankAccount::create(['name' => 'Corretora XP', 'type' => 'investment', 'initial_balance' => 0, 'status' => true]);

        Volt::test('investments.operations.index')
            ->call('create')
            ->call('chooseType', 'buy')
            ->assertSee('Cancelar')
            ->assertSee('Salvar')
            ->assertSee('Salvar e novo')
            ->assertSeeHtml('data-test="save-and-create-another"');
    }

    public function test_commission_form_shows_cancel_save_and_save_and_new(): void
    {
        $this->actingAs(User::factory()->create());
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        CaseType::create(['name' => 'RPV', 'status' => true]);

        Volt::test('brokers.commissions.form', ['broker' => $broker])
            ->assertSee('Cancelar')
            ->assertSee('Salvar')
            ->assertSee('Salvar e novo')
            ->assertSeeHtml('data-test="save-and-create-another"');
    }

    public function test_advance_form_shows_cancel_save_and_save_and_new(): void
    {
        $this->actingAs(User::factory()->create());
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);

        Volt::test('brokers.advances.form', ['broker' => $broker])
            ->assertSee('Cancelar')
            ->assertSee('Salvar')
            ->assertSee('Salvar e novo')
            ->assertSeeHtml('data-test="save-and-create-another"');
    }

    public function test_process_expense_create_shows_save_and_new_and_resets_after_success(): void
    {
        $this->actingAs(User::factory()->create());
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $account = BankAccount::create(['name' => 'Caixa', 'type' => 'cash', 'initial_balance' => 5000, 'status' => true]);

        Volt::test('brokers.process-expenses', ['brokerId' => $broker->id])
            ->call('create')
            ->assertSee('Cancelar')
            ->assertSee('Salvar')
            ->assertSee('Salvar e novo')
            ->set('date', '2026-11-05')
            ->set('amount', '100.00')
            ->set('description', 'Custas')
            ->set('accountId', $account->id)
            ->call('saveAndCreateAnother')
            ->assertHasNoErrors()
            ->assertSet('showForm', true)
            ->assertSet('editingId', null)
            ->assertSet('amount', '')
            ->assertSet('description', '')
            ->assertSet('formSuccessMessage', 'Despesa salva.');

        $this->assertDatabaseHas('broker_process_expenses', [
            'broker_id' => $broker->id,
            'description' => 'Custas',
        ]);
    }

    public function test_contacts_create_uses_salvar_without_save_and_new(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('contacts.index')
            ->call('create')
            ->assertSee('Cancelar')
            ->assertSee('Salvar')
            ->assertDontSee('Criar Contato')
            ->assertDontSeeHtml('data-test="save-and-create-another"');
    }
}
