<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_picker_label_includes_name_and_type(): void
    {
        $checking = BankAccount::create(['name' => 'Banco do Brasil', 'type' => 'checking', 'status' => true]);
        $fund = BankAccount::create(['name' => 'BB Fundo (aplicação automática)', 'type' => 'fund', 'status' => true]);
        $investment = BankAccount::create(['name' => 'BB Conta investimento', 'type' => 'investment', 'status' => true]);

        $this->assertEquals('Banco do Brasil · Corrente', $checking->pickerLabel());
        $this->assertEquals('BB Fundo (aplicação automática) · Fundo', $fund->pickerLabel());
        $this->assertEquals('BB Conta investimento · Investimento', $investment->pickerLabel());
        $this->assertTrue($fund->isInvestment());
        $this->assertTrue($investment->isInvestment());
    }

    public function test_picker_options_group_by_type_and_are_searchable_by_type(): void
    {
        BankAccount::create(['name' => 'Itaú', 'type' => 'checking', 'status' => true]);
        BankAccount::create(['name' => 'BB Fundo (aplicação automática)', 'type' => 'fund', 'status' => true]);
        BankAccount::create(['name' => 'BB Conta investimento', 'type' => 'investment', 'status' => true]);
        BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'status' => true]);

        $options = BankAccount::pickerOptions(BankAccount::active()->get());

        $headers = $options->where('is_header', true)->pluck('label')->all();
        $this->assertContains('Corrente', $headers);
        $this->assertContains('Investimento', $headers);
        $this->assertContains('Fundo (aplicação automática)', $headers);

        $fundOption = $options->first(fn ($o) => ($o['type'] ?? null) === 'fund' && empty($o['is_header']));
        $this->assertNotNull($fundOption);
        $this->assertStringContainsString('Fundo', $fundOption['label']);
        $this->assertStringContainsString('fundo', mb_strtolower($fundOption['search']));

        // Mesmo banco, tipos diferentes, rótulos distintos
        $labels = $options->where(fn ($o) => empty($o['is_header']))->pluck('label');
        $this->assertTrue($labels->contains('BB Fundo (aplicação automática) · Fundo'));
        $this->assertTrue($labels->contains('BB Conta investimento · Investimento'));
    }
}
