<?php

namespace Tests\Feature\Ui;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerProfileService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa3UiCorrecoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_nav_order_and_labels(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $labels = ['Dashboard', 'Financeiro', 'Investimentos', 'Requisitório', 'Contatos', 'Corretores', 'Sociedade', 'Transmissão'];

        foreach ($labels as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertStringNotContainsString("'label' => 'Investimento'", file_get_contents(resource_path('views/components/app-nav.blade.php')));
        $this->assertStringNotContainsString("'label' => 'Requisitórios'", file_get_contents(resource_path('views/components/app-nav.blade.php')));

        $nav = file_get_contents(resource_path('views/components/app-nav.blade.php'));
        $pos = [];
        foreach ($labels as $label) {
            $needle = "'label' => '".$label."'";
            $pos[$label] = strpos($nav, $needle);
            $this->assertNotFalse($pos[$label], $label.' missing in app-nav');
        }
        $ordered = array_values($pos);
        $sorted = $ordered;
        sort($sorted);
        $this->assertSame($sorted, $ordered, 'Ordem do menu em app-nav divergiu');
    }

    public function test_contact_modal_accepts_multiple_roles(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('contacts.index')
            ->call('create')
            ->assertSee('Papéis')
            ->set('name', 'Modal Multi')
            ->set('roles', ['cedente', 'cliente'])
            ->set('document', '390.533.447-05')
            ->call('save')
            ->assertHasNoErrors();

        $contact = Contact::where('name', 'Modal Multi')->firstOrFail();
        $this->assertTrue($contact->hasRole('cedente'));
        $this->assertTrue($contact->hasRole('cliente'));
    }

    public function test_searchable_select_blade_has_valid_header_visible_js(): void
    {
        $blade = file_get_contents(resource_path('views/components/jr/searchable-select.blade.php'));
        $this->assertStringContainsString("querySelectorAll('[data-option-search]')", $blade);
        $this->assertStringNotContainsString('querySelectorAll([data-option-search])', $blade);
        $this->assertStringContainsString("(item.dataset.optionType || '')", $blade);
        $this->assertStringNotContainsString('(item.dataset.optionType || )', $blade);
    }

    public function test_expense_modal_lists_accounts_and_categories_in_picker_markup(): void
    {
        $this->actingAs(User::factory()->create());

        BankAccount::create([
            'name' => 'Conta Teste Picker',
            'type' => BankAccount::TYPE_CHECKING,
            'initial_balance' => 100,
            'status' => true,
        ]);
        Category::create([
            'name' => 'Categoria Teste Picker',
            'type' => 'expense',
            'status' => true,
        ]);

        Volt::test('banking.transactions.index')
            ->call('create', 'expense')
            ->assertSee('Conta Teste Picker')
            ->assertSee('Categoria Teste Picker')
            ->assertSee('Corrente');
    }

    public function test_broker_launch_modal_supports_percent_and_fixed_commission(): void
    {
        $this->actingAs(User::factory()->create());

        $contact = Contact::create([
            'name' => 'Corretor Modal',
            'type' => 'corretor',
            'roles' => ['corretor'],
            'status' => true,
        ]);
        $broker = app(BrokerProfileService::class)->forContact($contact);
        $caseType = CaseType::create(['name' => 'Previdenciário']);

        Volt::test('brokers.index')
            ->call('openLaunchModal', $contact->id, 'commission')
            ->assertSee('Modo')
            ->assertSee('Percentual')
            ->assertSee('Valor fixo')
            ->set('launch_mode', 'percent')
            ->set('launch_case_type_id', $caseType->id)
            ->set('launch_name', 'Cliente %')
            ->set('launch_amount', '10')
            ->set('launch_base_amount', '1000')
            ->set('launch_date', now()->toDateString())
            ->call('saveLaunch')
            ->assertHasNoErrors();

        $percent = BrokerCommission::where('name', 'Cliente %')->firstOrFail();
        $this->assertSame('percent', $percent->commission_mode);
        $this->assertEquals(100.0, (float) $percent->commission_amount);

        Volt::test('brokers.index')
            ->call('openLaunchModal', $contact->id, 'commission')
            ->set('launch_mode', 'fixed')
            ->set('launch_case_type_id', $caseType->id)
            ->set('launch_name', 'Cliente Fixo')
            ->set('launch_amount', '250')
            ->set('launch_date', now()->toDateString())
            ->call('saveLaunch')
            ->assertHasNoErrors();

        $fixed = BrokerCommission::where('name', 'Cliente Fixo')->firstOrFail();
        $this->assertSame('fixed', $fixed->commission_mode);
        $this->assertEquals(250.0, (float) $fixed->commission_amount);
        $this->assertEquals(2, BrokerCommission::where('broker_id', $broker->id)->count());
    }
}
