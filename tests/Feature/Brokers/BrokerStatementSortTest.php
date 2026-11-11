<?php

namespace Tests\Feature\Brokers;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerProfileService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Corretores › ficha › Extrato (e abas): setinhas de ordenação, saldo corrido calculado na ordem cronológica. */
class BrokerStatementSortTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contact $contact;

    private BankAccount $account;

    private CaseType $caseType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->user->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($this->user);
        $this->account = BankAccount::create(['name' => 'Conta Corrente', 'initial_balance' => 10000]);
        $this->caseType = CaseType::create(['name' => 'Previdenciário']);
        $this->contact = Contact::create(['name' => 'Milka', 'type' => 'corretor', 'status' => true]);
        $financial = app(BrokerProfileService::class)->forContact($this->contact);
        app(BrokerAdvanceService::class)->register([
            'broker_id' => $financial->id,
            'date' => '2026-01-10',
            'amount' => 100.00,
            'bank_account_id' => $this->account->id,
            'notes' => 'Adiantamento janeiro',
        ]);
        $commission = app(BrokerCommissionService::class)->registerFixedAmount([
            'broker_id' => $financial->id,
            'case_type_id' => $this->caseType->id,
            'name' => 'Cliente Alfa',
            'commission_amount' => 80.00,
            'reference_date' => '2026-01-20',
        ]);
        app(BrokerCommissionService::class)->payAmount($commission, 20.00, '2026-02-01', $this->account->id);
    }

    private function entries($c): array
    {
        return $c->viewData('statementEntries')->map(fn ($e) => [
            'type' => $e['type'],
            'date' => $e['date']->toDateString(),
            'amount' => (float) $e['amount'],
            'balance' => $e['running_balance'] ?? null,
        ])->all();
    }

    public function test_statement_defaults_to_newest_first_with_arrows_and_keeps_running_balance(): void
    {
        $c = Volt::test('brokers.show', ['broker' => $this->contact])
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDir', 'desc')
            ->assertSeeHtml('data-sort-col="date"')
            ->assertSeeHtml('data-sort-col="movement"')
            ->assertSeeHtml('data-sort-col="description"')
            ->assertSeeHtml('data-sort-col="amount"')
            ->assertSeeHtml('data-sort-col="balance"')
            ->assertSeeHtml('data-sort-active="desc"')
            ->assertSeeHtml('data-sort-select')
            ->assertSeeHtml('data-sort-mobile');

        $rows = $this->entries($c);
        $this->assertSame(['2026-02-01', '2026-01-20', '2026-01-10'], array_column($rows, 'date'));
        $this->assertSame(['Repasse', 'Comissão gerada', 'Adiantamento pessoal'], array_column($rows, 'type'));
        // Saldo corrido (saldo_devedor): adianta 100 → 100; comissão 80 → 20; repasse 20 → 40. Vai com a linha, mesmo invertida.
        $byType = collect($rows)->keyBy('type');
        $this->assertEquals(100.0, $byType['Adiantamento pessoal']['balance']);
        $this->assertEquals(20.0, $byType['Comissão gerada']['balance']);
        $this->assertEquals(40.0, $byType['Repasse']['balance']);
    }

    public function test_date_ascending_uses_real_date_and_is_remembered(): void
    {
        $c = Volt::test('brokers.show', ['broker' => $this->contact])
            ->call('sortByColumn', 'date')
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDir', 'asc')
            ->assertSeeHtml('data-sort-active="asc"');
        $this->assertSame(['2026-01-10', '2026-01-20', '2026-02-01'], array_column($this->entries($c), 'date'));
        $byType = collect($this->entries($c))->keyBy('type');
        $this->assertEquals(100.0, $byType['Adiantamento pessoal']['balance']);
        $this->assertEquals(20.0, $byType['Comissão gerada']['balance']);
        $this->assertEquals(40.0, $byType['Repasse']['balance']);
        $this->assertSame(['date', 'asc'], UserPreferences::sort($this->user->fresh(), 'broker_statement'));

        Volt::test('brokers.show', ['broker' => $this->contact])->assertSet('sortDir', 'asc');
        $other = User::factory()->create();
        $other->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($other);
        Volt::test('brokers.show', ['broker' => $this->contact])->assertSet('sortBy', 'date')->assertSet('sortDir', 'desc');
    }

    public function test_amount_and_movement_sort_and_second_tap_inverts(): void
    {
        $c = Volt::test('brokers.show', ['broker' => $this->contact])->call('sortByColumn', 'amount')->assertSet('sortDir', 'asc');
        $this->assertSame([20.0, 80.0, 100.0], array_column($this->entries($c), 'amount'));
        $c->call('sortByColumn', 'amount')->assertSet('sortDir', 'desc');
        $this->assertSame([100.0, 80.0, 20.0], array_column($this->entries($c), 'amount'));
        $c->call('sortByColumn', 'movement')->assertSet('sortBy', 'movement')->assertSet('sortDir', 'asc');
        $this->assertSame(['Adiantamento pessoal', 'Comissão gerada', 'Repasse'], array_column($this->entries($c), 'type'));
    }

    public function test_advances_tab_sorts_independently_and_invalid_values_are_ignored(): void
    {
        $c = Volt::test('brokers.show', ['broker' => $this->contact])
            ->set('records_tab', 'advances')
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDir', 'desc')
            ->assertSeeHtml('data-sort-col="payment_method"')
            ->call('sortByColumn', 'amount')
            ->assertSet('sortBy', 'amount')
            ->assertSet('sortDir', 'asc')
            ->call('sortByColumn', 'nada')
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDir', 'desc');
        $this->assertSame(['date', 'desc'], UserPreferences::validSort('evil', 'sideways', 'broker_advances'));
        $this->assertSame(['amount', 'asc'], UserPreferences::validSort('amount', 'sideways', 'broker_advances'));
        $this->assertSame(['date', 'desc'], UserPreferences::sort($this->user->fresh(), 'broker_statement'), 'não mistura com o extrato');
        $c->set('records_tab', 'statement')->assertSet('sortBy', 'date')->assertSet('sortDir', 'desc');
    }

    public function test_process_expenses_table_has_sort_arrows(): void
    {
        Volt::test('brokers.process-expenses', ['brokerId' => app(BrokerProfileService::class)->forContact($this->contact)->id])
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDir', 'desc');
    }
}
