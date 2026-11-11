<?php

namespace Tests\Feature\Ui;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Garante que as listas com setinhas carregam a ordenação padrão e rejeitam coluna inválida. */
class TableSortSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($this->admin);
        BankAccount::create(['name' => 'BTG', 'type' => 'checking', 'initial_balance' => 0, 'initial_balance_date' => now()->startOfMonth()->toDateString(), 'status' => true]);
        Contact::create(['name' => 'Ana', 'type' => 'cedente', 'status' => true]);
        Contact::create(['name' => 'Bruno Corretor', 'type' => 'corretor', 'status' => true]);
    }

    public function test_key_screens_load_default_sort_and_reject_invalid_column(): void
    {
        $screens = [
            'banking.accounts.index' => ['accounts', 'name', 'asc', 'balance'],
            'banking.cards.index' => ['cards', 'name', 'asc', 'due'],
            'banking.recurring.index' => ['recurring', 'description', 'asc', 'amount'],
            'banking.loans.index' => ['loans', 'date', 'desc', 'balance'],
            'banking.receivables.index' => ['receivables', 'due', 'asc', 'person'],
            'banking.reports.cashflow' => ['cashflow_items', 'date', 'asc', 'description'],
            'contacts.index' => ['contacts', 'name', 'asc', 'email'],
            'bens.index' => ['properties', 'name', 'asc', 'current'],
            'brokers.index' => ['brokers', 'name', 'asc', 'advance_balance'],
            'brokers.tipos-caso.index' => ['case_types', 'name', 'asc', 'status'],
        ];

        foreach ($screens as $component => [$list, $defaultBy, $defaultDir, $other]) {
            $c = Volt::test($component)->assertSet('sortBy', $defaultBy)->assertSet('sortDir', $defaultDir);
            $c->call('sortByColumn', $other)->assertSet('sortBy', $other)->assertSet('sortDir', 'asc');
            $c->call('sortByColumn', 'coluna-invalida')->assertSet('sortBy', $defaultBy)->assertSet('sortDir', $defaultDir);
            $this->assertSame([$defaultBy, $defaultDir], UserPreferences::sort($this->admin->fresh(), $list), $component);
        }
    }
}
