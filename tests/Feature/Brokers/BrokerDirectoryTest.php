<?php

namespace Tests\Feature\Brokers;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerProfileService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BrokerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_only_broker_becomes_searchable_without_moving_his_commission(): void
    {
        $this->actingAs(User::factory()->create());
        $broker = Broker::create(['name' => 'JAIRO RODRIGUES', 'status' => true, 'bank_name' => 'BB', 'phone' => '999999999', 'address' => 'Endereço antigo']);
        $type = CaseType::create(['name' => 'Previdenciário', 'status' => true]);
        $commission = BrokerCommission::create(['broker_id' => $broker->id, 'case_type_id' => $type->id, 'name' => 'Cliente', 'base_amount' => 100, 'percentage_applied' => 0, 'commission_amount' => 100, 'status' => 'pending', 'reference_date' => now()->toDateString()]);
        Volt::test('brokers.index')->set('search', 'JAIRO')->assertSee('JAIRO RODRIGUES');
        $contact = $broker->fresh()->contact;
        $this->assertNotNull($contact);
        $this->assertSame('corretor', $contact->type);
        $this->assertSame($broker->id, $commission->fresh()->broker_id);
        $this->assertSame('BB', $contact->bank_name);
        $this->assertSame('Endereço antigo', $contact->address);
        $this->assertSame('Endereço antigo', $broker->fresh()->address);
        $this->get(route('brokers.show', ['broker' => $contact->id, 'records_tab' => 'commissions']))->assertOk()->assertSee('Cliente')->assertSee('Previdenciário');
        Volt::test('brokers.index')->set('search', 'JAIRO')->assertSee('JAIRO RODRIGUES');
        $this->assertDatabaseCount('brokers', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('broker_commissions', 1);
    }

    public function test_existing_contact_is_reused_by_document_and_keeps_its_current_data(): void
    {
        $contact = Contact::create(['name' => 'Nome Atualizado', 'type' => 'corretor', 'status' => true, 'document' => '123.456.789-09', 'phone' => '111111111', 'bank_name' => 'Itaú']);
        $broker = Broker::create(['name' => 'Nome Antigo', 'status' => true, 'document' => '12345678909', 'phone' => '222222222', 'bank_name' => 'BB', 'bank_account' => '1234']);
        $service = app(BrokerProfileService::class);
        $service->synchronizeDirectory();
        $service->synchronizeDirectory();
        $this->assertSame($contact->id, $broker->fresh()->contact_id);
        $this->assertSame('111111111', $contact->fresh()->phone);
        $this->assertSame('Itaú', $contact->fresh()->bank_name);
        $this->assertSame('1234', $contact->fresh()->bank_account);
        $this->assertSame('Nome Atualizado', $broker->fresh()->name);
        $this->assertDatabaseCount('brokers', 1);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_homonyms_with_different_documents_keep_separate_financial_profiles(): void
    {
        $contact = Contact::create(['name' => 'Mesmo Nome', 'type' => 'corretor', 'status' => true, 'document' => '11111111111']);
        $legacy = Broker::create(['name' => 'Mesmo Nome', 'status' => true, 'document' => '22222222222']);
        app(BrokerProfileService::class)->synchronizeDirectory();
        $this->assertNotSame($contact->id, $legacy->fresh()->contact_id);
        $this->assertNotSame($legacy->id, $contact->fresh()->brokerProfile->id);
        $this->assertDatabaseCount('brokers', 2);
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_receipt_options_include_new_broker_contacts_and_exclude_invalid_or_deleted_contacts(): void
    {
        $this->actingAs(User::factory()->create());
        $contact = Contact::create(['name' => 'Corretor novo', 'type' => 'corretor', 'status' => true]);
        $inactive = Contact::create(['name' => 'Inativo', 'type' => 'corretor', 'status' => false]);
        Broker::create(['name' => 'Inativo', 'contact_id' => $inactive->id, 'status' => true]);
        $deleted = Contact::create(['name' => 'Removido', 'type' => 'corretor', 'status' => true]);
        Broker::create(['name' => 'Removido', 'contact_id' => $deleted->id, 'status' => true]);
        $deleted->delete();
        $other = Contact::create(['name' => 'Cedente', 'type' => 'cedente', 'status' => true]);
        Broker::create(['name' => 'Cedente', 'contact_id' => $other->id, 'status' => true]);
        $account = BankAccount::create(['name' => 'BB']);
        $receipt = Transaction::create(['type' => 'income', 'status' => 'settled', 'description' => 'Recebimento', 'date' => now()->toDateString(), 'amount' => '1000.00', 'bank_account_id' => $account->id]);
        $component = Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id]);
        $options = $component->viewData('brokers');
        $this->assertSame([$contact->brokerProfile->id], $options->pluck('id')->all());
        $this->assertSame('Corretor novo', $options->sole()->name);
    }

    public function test_migration_links_existing_financial_records_idempotently(): void
    {
        $broker = Broker::create(['name' => 'Legado', 'status' => true]);
        $migration = require database_path('migrations/2026_10_20_010000_link_legacy_brokers_to_contacts.php');
        $migration->up();
        $contactId = $broker->fresh()->contact_id;
        $migration->up();
        $migration->down();
        $this->assertSame($contactId, $broker->fresh()->contact_id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('brokers', 1);
    }
}
