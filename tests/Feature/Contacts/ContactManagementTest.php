<?php

namespace Tests\Feature\Contacts;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerProfileService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Services\CepLookupService;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Models\WritAssignor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ContactManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_type_label_identifies_broker(): void
    {
        $contact = Contact::create([
            'name' => 'Jairo Corretor',
            'type' => 'corretor',
            'phones' => ['(99) 99999-9999', '(98) 98888-8888'],
            'emails' => ['jairo@example.com', 'financeiro@example.com'],
            'pix_key_type' => 'telefone',
        ]);

        $this->assertEquals('Corretor', $contact->typeLabel());
        $this->assertEquals('Telefone', $contact->pixKeyTypeLabel());
        $this->assertCount(2, $contact->phones);
        $this->assertCount(2, $contact->emails);
    }

    public function test_cep_lookup_returns_normalized_address_data(): void
    {
        Http::fake([
            'viacep.com.br/ws/01001000/json/' => Http::response([
                'logradouro' => 'Praça da Sé',
                'complemento' => 'lado ímpar',
                'bairro' => 'Sé',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
            ]),
        ]);

        $result = app(CepLookupService::class)->lookup('01001-000');

        $this->assertSame([
            'zip_code' => '01001-000',
            'street' => 'Praça da Sé',
            'complement' => 'lado ímpar',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $result);
    }

    public function test_contact_form_fills_and_saves_neighborhood_from_zip_code(): void
    {
        Http::fake([
            'viacep.com.br/ws/65900150/json/' => Http::response([
                'logradouro' => 'Rua Benjamin Constant',
                'complemento' => '',
                'bairro' => 'Centro',
                'localidade' => 'Imperatriz',
                'uf' => 'MA',
            ]),
        ]);

        $this->actingAs(User::factory()->create());

        Volt::test('contacts.form')
            ->set('name', 'Contato com Bairro')
            ->set('zip_code', '65900-150')
            ->call('lookupZipCode')
            ->assertSet('neighborhood', 'Centro')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('contacts', [
            'name' => 'Contato com Bairro',
            'zip_code' => '65900-150',
            'street' => 'Rua Benjamin Constant',
            'neighborhood' => 'Centro',
            'city' => 'Imperatriz',
            'state' => 'MA',
        ]);
    }

    public function test_contact_modal_fills_neighborhood_from_zip_code(): void
    {
        Http::fake([
            'viacep.com.br/ws/65900150/json/' => Http::response([
                'logradouro' => 'Rua Benjamin Constant',
                'complemento' => '',
                'bairro' => 'Centro',
                'localidade' => 'Imperatriz',
                'uf' => 'MA',
            ]),
        ]);

        $this->actingAs(User::factory()->create());

        Volt::test('contacts.index')
            ->call('create')
            ->set('zip_code', '65900-150')
            ->call('lookupZipCode')
            ->assertSet('neighborhood', 'Centro');
    }

    public function test_contact_with_linked_writ_cannot_be_deleted(): void
    {
        $contact = Contact::create(['name' => 'Cedente Vinculado']);
        $writ = Writ::create(['type' => 'rpv']);

        WritAssignor::create([
            'writ_id' => $writ->id,
            'contact_id' => $contact->id,
            'role' => 'parte',
        ]);

        $this->assertFalse($contact->canBeDeleted());
        $this->assertStringContainsString('requisitório', $contact->deletionBlockMessage());
    }

    public function test_contact_with_linked_transaction_cannot_be_deleted(): void
    {
        $contact = Contact::create(['name' => 'Contato com Lançamento']);

        Transaction::create([
            'type' => 'expense',
            'date' => now()->toDateString(),
            'amount' => 100,
            'description' => 'Lançamento vinculado',
            'source_type' => Contact::class,
            'source_id' => $contact->id,
        ]);

        $this->assertFalse($contact->canBeDeleted());
        $this->assertStringContainsString('lançamento', $contact->deletionBlockMessage());
    }

    public function test_broker_detail_route_renders_for_broker_contact(): void
    {
        $this->actingAs(User::factory()->create());

        $contact = Contact::create([
            'name' => 'Corretor Centralizado',
            'type' => 'corretor',
            'status' => true,
        ]);

        $this->get(route('brokers.show', $contact))
            ->assertOk()
            ->assertSee('Corretor Centralizado')
            ->assertSee('Corretor');
    }

    public function test_broker_show_renders_commission_name_field_and_table(): void
    {
        $this->actingAs(User::factory()->create());

        $contact = Contact::create([
            'name' => 'Corretor Com Nome',
            'type' => 'corretor',
            'status' => true,
        ]);

        $financial = app(BrokerProfileService::class)->forContact($contact);
        $caseType = CaseType::create(['name' => 'Previdenciário', 'status' => true]);

        BrokerCommission::create([
            'broker_id' => $financial->id,
            'case_type_id' => $caseType->id,
            'name' => 'Cliente Teste',
            'base_amount' => 100,
            'percentage_applied' => 0,
            'commission_amount' => 100,
            'status' => 'pending',
            'reference_date' => now()->toDateString(),
        ]);

        $this->get(route('brokers.show', ['broker' => $contact, 'records_tab' => 'commissions']))
            ->assertOk()
            ->assertSee('Cliente Teste')
            ->assertSee('Previdenciário');
    }
}
