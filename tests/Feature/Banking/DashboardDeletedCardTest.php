<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardDeletedCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_ignores_invoices_of_deleted_card(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($u);
        $card = CreditCard::create(['name' => 'Nubank', 'limit' => 0, 'closing_day' => 1, 'due_day' => 10]);
        CreditCardInvoice::create(['credit_card_id' => $card->id, 'reference_month' => now()->addMonth()->format('Y-m'), 'closing_date' => now()->addDays(5)->toDateString(), 'due_date' => now()->addDays(15)->toDateString(), 'status' => 'open', 'total' => 0]);
        $card->delete();
        Volt::test('banking.dashboard')->assertOk();
        $this->get(route('banking.dashboard'))->assertOk();
    }
}
