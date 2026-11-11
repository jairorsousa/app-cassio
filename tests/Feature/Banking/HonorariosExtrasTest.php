<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\ProcessReceipt;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Brokers\Models\Broker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class HonorariosExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
    }

    /**
     * @return array{0: Transaction, 1: Broker, 2: Category, 3: array<string, mixed>}
     */
    private function example(): array
    {
        $this->actingAs(User::factory()->create());
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000, 'status' => true]);
        BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        $category = Category::create(['name' => 'Variável (Comissões/Bônus)', 'type' => 'income', 'status' => true]);
        $receipt = Transaction::create([
            'type' => 'income', 'amount' => '10000.00', 'date' => now()->toDateString(),
            'description' => 'Recebimento', 'status' => 'settled', 'bank_account_id' => $account->id,
        ]);
        $data = [
            'client_name' => 'Cliente', 'process_ref' => '0000000-00.0000.0.00.0001',
            'fees' => '3000.00', 'fees_mode' => 'amount', 'office_amount' => '30', 'commission_mode' => 'percent',
            'category_id' => $category->id, 'expenses' => [], 'advances' => [],
            'brokers' => [[
                'broker_id' => $broker->id, 'base' => 'fees', 'mode' => 'percent', 'value' => '20', 'auto' => false, 'advances' => [],
            ]],
        ];

        return [$receipt, $broker, $category, $data];
    }

    public function test_form_shows_meus_honorarios_and_new_office_label(): void
    {
        [$receipt] = $this->example();
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])
            ->assertSee('1. Meus honorários')
            ->assertSee('Honorário principal é o valor do processo')
            ->assertSee('+ Adicionar honorário extra')
            ->assertSee('Este recebimento é só honorário extra do mesmo processo')
            ->assertSee('Parte do escritório (Mota e Silva Advogados)')
            ->assertDontSee('Comissão do escritório');
    }

    public function test_existing_style_receipt_without_extras_keeps_the_same_split(): void
    {
        [$receipt, , $category, $data] = $this->example();
        $values = app(ProcessReceiptService::class)->calculate($receipt, $data);
        $this->assertSame(1000000, $values['total']);
        $this->assertSame(300000, $values['fees']);
        $this->assertSame(0, $values['extras']);
        $this->assertSame(300000, $values['commission_base']);
        $this->assertSame(700000, $values['client']);
        $this->assertSame(90000, $values['office']);
        $this->assertSame(60000, $values['broker']);
        $this->assertSame(150000, $values['own']);
        app(ProcessReceiptService::class)->save($receipt, $data);
        $process = $receipt->fresh()->processReceipt;
        $this->assertSame('3000.00', $process->fees_amount);
        $this->assertFalse($process->is_extra_only);
        $this->assertSame(0, $process->feeItems()->count());
        $this->assertSame($category->id, $process->category_id);
        $this->assertSame('7000.00', $receipt->destinations()->where('kind', 'client')->sole()->amount);
        $this->assertSame('1500.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame('900.00', $receipt->destinations()->where('kind', 'office')->sole()->amount);
        $this->assertSame('600.00', $receipt->destinations()->where('kind', 'broker')->sole()->amount);
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertEquals(1500, $report->viewData('ownIncome'));
        $this->assertEquals(1500, $report->viewData('byCategory')->get($category->name)['income']);
    }

    public function test_extra_outside_base_reduces_client_and_increases_own_without_changing_commissions(): void
    {
        [$receipt, , $category, $data] = $this->example();
        $data['fee_extras'] = [[
            'type' => 'extra', 'description' => 'Honorário extra', 'amount' => '500.00', 'in_commission_base' => false,
        ]];
        $values = app(ProcessReceiptService::class)->calculate($receipt, $data);
        $this->assertSame(300000, $values['fees']);
        $this->assertSame(50000, $values['extras']);
        $this->assertSame(0, $values['extras_in_base']);
        $this->assertSame(300000, $values['commission_base']);
        $this->assertSame(350000, $values['fees_total']);
        $this->assertSame(650000, $values['client']);
        $this->assertSame(90000, $values['office']);
        $this->assertSame(60000, $values['broker']);
        $this->assertSame(200000, $values['own']);
        $this->assertSame($values['total'], $values['client'] + $values['office'] + $values['broker'] + $values['own']);
        app(ProcessReceiptService::class)->save($receipt, $data);
        $process = $receipt->fresh()->processReceipt()->with('feeItems')->first();
        $this->assertSame('3000.00', $process->fees_amount);
        $this->assertSame(50000, $process->extrasCents());
        $this->assertSame(350000, $process->totalFeesCents());
        $item = $process->feeItems->sole();
        $this->assertSame('extra', $item->kind);
        $this->assertSame('Honorário extra', $item->description);
        $this->assertSame('500.00', $item->amount);
        $this->assertFalse($item->in_commission_base);
        $this->assertSame('6500.00', $receipt->destinations()->where('kind', 'client')->sole()->amount);
        $this->assertSame('2000.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame('900.00', $receipt->destinations()->where('kind', 'office')->sole()->amount);
        $this->assertSame('600.00', $receipt->destinations()->where('kind', 'broker')->sole()->amount);
        $this->assertSame($category->id, $process->category_id);
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])
            ->assertSee('Total dos honorários')
            ->assertSee('Honorário extra')
            ->assertSee('R$ 500,00')
            ->assertSee('Minha receita')
            ->assertSee('R$ 2.000,00');
        $report = Volt::test('banking.reports.cashflow')->set('month', $receipt->date->format('Y-m'));
        $this->assertEquals(2000, $report->viewData('ownIncome'));
        $this->assertEquals(2000, $report->viewData('byCategory')->get($category->name)['income']);
    }

    public function test_extra_marked_in_base_increases_office_and_broker_percentages(): void
    {
        [$receipt, , , $data] = $this->example();
        $data['fee_extras'] = [[
            'type' => 'other', 'description' => 'Outro valor', 'amount' => '500.00', 'in_commission_base' => true,
        ]];
        $values = app(ProcessReceiptService::class)->calculate($receipt, $data);
        $this->assertSame(350000, $values['commission_base']);
        $this->assertSame(350000, $values['fees_total']);
        $this->assertSame(650000, $values['client']);
        $this->assertSame(105000, $values['office']);
        $this->assertSame(70000, $values['broker']);
        $this->assertSame(175000, $values['own']);
        $this->assertSame($values['total'], $values['client'] + $values['office'] + $values['broker'] + $values['own']);
        app(ProcessReceiptService::class)->save($receipt, $data);
        $item = $receipt->fresh()->processReceipt->feeItems()->sole();
        $this->assertSame('other', $item->kind);
        $this->assertTrue($item->in_commission_base);
        Volt::test('banking.transactions.distribution', ['receiptId' => $receipt->id])
            ->assertSee('entra na base do escritório e do corretor');
    }

    public function test_principal_plus_extras_cannot_exceed_the_bank_entry(): void
    {
        [$receipt, , , $data] = $this->example();
        $data['fee_extras'] = [['type' => 'extra', 'description' => 'Extra', 'amount' => '8000.00', 'in_commission_base' => false]];
        try {
            app(ProcessReceiptService::class)->save($receipt, $data);
            $this->fail('Overflow must fail');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('não podem superar a entrada', $e->getMessage());
        }
        $this->assertDatabaseCount('process_receipts', 0);
        $this->assertDatabaseCount('process_receipt_fee_items', 0);
        $this->assertDatabaseCount('receipt_destinations', 0);
    }

    public function test_extra_only_receipt_of_the_same_process_has_no_commission_by_default(): void
    {
        [$first, , $category, $data] = $this->example();
        app(ProcessReceiptService::class)->save($first, $data);
        $account = $first->bankAccount;
        $second = Transaction::create([
            'type' => 'income', 'amount' => '800.00', 'date' => now()->toDateString(),
            'description' => 'Honorário extra', 'status' => 'settled', 'bank_account_id' => $account->id,
        ]);
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $second->id])
            ->set('form.is_extra_only', true)
            ->assertSet('form.fees', '0.00')
            ->assertSet('form.office_amount', '0.00')
            ->assertSet('form.fee_extras.0.type', 'extra')
            ->assertSet('form.fee_extras.0.amount', '800.00')
            ->assertSet('form.fee_extras.0.in_commission_base', false)
            ->set('form.client_name', 'Cliente')
            ->set('form.process_ref', '0000000-00.0000.0.00.0001')
            ->set('form.category_id', $category->id)
            ->set('form.fees_mode', 'amount')
            ->set('form.commission_mode', 'amount')
            ->call('save')
            ->assertHasNoErrors();
        $process = ProcessReceipt::where('receipt_id', $second->id)->sole();
        $this->assertTrue($process->is_extra_only);
        $this->assertSame('0000000-00.0000.0.00.0001', $process->process_ref);
        $this->assertSame('0.00', $process->fees_amount);
        $this->assertSame('800.00', $process->feeItems()->sole()->amount);
        $this->assertFalse((bool) $process->feeItems()->sole()->in_commission_base);
        $this->assertSame($category->id, $process->category_id);
        $this->assertSame('0.00', $second->destinations()->where('kind', 'client')->sole()->amount);
        $this->assertSame('800.00', $second->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertFalse($second->destinations()->where('kind', 'office')->exists());
        $this->assertFalse($second->destinations()->where('kind', 'broker')->exists());
        Volt::test('banking.transactions.distribution', ['receiptId' => $second->id])
            ->assertSee('Só honorário extra');
    }

    public function test_extra_only_requires_the_process_number(): void
    {
        [$receipt, , $category] = $this->example();
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])
            ->set('form.is_extra_only', true)
            ->set('form.client_name', 'Cliente')
            ->set('form.process_ref', '')
            ->set('form.category_id', $category->id)
            ->set('form.fees_mode', 'amount')
            ->set('form.fees', '0.00')
            ->set('form.commission_mode', 'amount')
            ->set('form.office_amount', '0.00')
            ->call('save')
            ->assertHasErrors('process_ref');
        $this->assertDatabaseCount('process_receipts', 0);
    }

    public function test_editing_an_existing_receipt_can_add_extras_without_changing_the_original_when_untouched(): void
    {
        [$receipt, , , $data] = $this->example();
        app(ProcessReceiptService::class)->save($receipt, $data);
        $original = [
            'fees' => $receipt->fresh()->processReceipt->fees_amount,
            'own' => $receipt->destinations()->where('kind', 'own')->sole()->amount,
            'client' => $receipt->destinations()->where('kind', 'client')->sole()->amount,
            'office' => $receipt->destinations()->where('kind', 'office')->sole()->amount,
            'broker' => $receipt->destinations()->where('kind', 'broker')->sole()->amount,
        ];
        $form = Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id]);
        $form->assertSet('form.fees', '3000.00');
        $this->assertSame([], $form->get('form')['fee_extras']);
        $this->assertFalse((bool) $form->get('form')['is_extra_only']);
        $data['fee_extras'] = [['type' => 'extra', 'description' => 'Extra na edição', 'amount' => '200.00', 'in_commission_base' => false]];
        app(ProcessReceiptService::class)->save($receipt->fresh(), $data);
        $this->assertSame('3000.00', $receipt->fresh()->processReceipt->fees_amount);
        $this->assertSame('3200.00', number_format($receipt->fresh()->processReceipt->totalFeesCents() / 100, 2, '.', ''));
        $this->assertSame('1700.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame('6800.00', $receipt->destinations()->where('kind', 'client')->sole()->amount);
        $this->assertSame($original['office'], $receipt->destinations()->where('kind', 'office')->sole()->amount);
        $this->assertSame($original['broker'], $receipt->destinations()->where('kind', 'broker')->sole()->amount);
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])
            ->assertSet('form.fee_extras.0.description', 'Extra na edição')
            ->assertSet('form.fee_extras.0.amount', '200.00');
    }
}
