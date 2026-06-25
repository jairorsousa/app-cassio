<?php

namespace Tests\Feature\Contacts;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Services\CepLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_type_label_identifies_broker(): void
    {
        $contact = Contact::create([
            'name' => 'Jairo Corretor',
            'type' => 'corretor',
        ]);

        $this->assertEquals('Corretor', $contact->typeLabel());
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
}
