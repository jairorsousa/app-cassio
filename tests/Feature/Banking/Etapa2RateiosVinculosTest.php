<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\ProcessReceiptBroker;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Models\TransactionAttachment;
use App\Domains\Banking\Services\ProcessReceiptService;
use App\Domains\Banking\Services\TransactionAllocationService;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Events\WritMovedToPaid;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritProfitabilityCalculator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa2RateiosVinculosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function base(string $amount = '78810.26'): array
    {
        $bank = BankAccount::create(['name' => 'CEF', 'initial_balance' => 0, 'status' => true]);
        $client = BankAccount::create(['name' => 'Cliente', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        BankAccount::create(['name' => 'Mota e Silva Advogados', 'type' => 'repasse', 'initial_balance' => 0, 'status' => true]);
        $category = Category::create(['name' => 'Comissões/Bônus', 'type' => 'income', 'status' => true]);
        $receipt = Transaction::create(['type' => 'income', 'amount' => $amount, 'date' => '2026-10-08', 'description' => 'Antônio Sérgio', 'status' => 'settled', 'bank_account_id' => $bank->id]);

        return [$bank, $client, $category, $receipt];
    }

    private function data(Category $category, array $brokers, array $extra = []): array
    {
        return array_replace([
            'client_name' => 'Antônio Sérgio Santos Sousa', 'fees' => '23643.08', 'fees_mode' => 'amount',
            'office_amount' => '8629.72', 'commission_mode' => 'amount', 'category_id' => $category->id,
            'expenses' => [], 'advances' => [], 'brokers' => $brokers,
        ], $extra);
    }

    public function test_rateio_fecha_a_soma_e_mostra_o_que_falta(): void
    {
        $bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 2000, 'status' => true]);
        $cats = collect(['Pneu', 'Escova de dente', 'Shampoo'])->map(fn ($n) => Category::create(['name' => $n, 'type' => 'expense', 'status' => true]));
        $rows = fn ($c) => [['key' => 'a', 'category_id' => $cats[0]->id, 'amount' => '400'], ['key' => 'b', 'category_id' => $cats[1]->id, 'amount' => '200'], ['key' => 'c', 'category_id' => $cats[2]->id, 'amount' => $c]];

        Volt::test('banking.transactions.index')->call('create', 'expense')
            ->assertSee('Dividir este lançamento em categorias')
            ->set('formAmount', '1000')->set('formDescription', 'Compra')->set('formBankAccountId', $bank->id)
            ->set('formAllocationEnabled', true)->set('formAllocationRows', $rows('300'))
            ->call('saveTransaction')->assertHasErrors('formAllocationRows')
            ->assertSee('A soma das categorias deve ser igual a R$ 1.000,00. Falta ratear R$ 100,00.');
        $this->assertSame(0, Transaction::count());

        Volt::test('banking.transactions.index')->call('create', 'expense')
            ->set('formAmount', '1000')->set('formDescription', 'Compra')->set('formBankAccountId', $bank->id)
            ->set('formAllocationEnabled', true)->set('formAllocationRows', $rows('400'))
            ->call('saveTransaction')->assertHasNoErrors();
        $this->assertSame(1, Transaction::count(), 'O rateio não cria outras saídas no banco.');
        $t = Transaction::sole();
        $this->assertSame(['400.00', '200.00', '400.00'], $t->allocations()->orderBy('id')->pluck('amount')->all());
        $this->assertSame(1000.0, $bank->fresh()->balance());
        Volt::test('banking.transactions.index')->set('from', '2026-10-01')->assertSee('3 categorias')->assertSee('Escova de dente: R$ 200,00');
    }

    public function test_caso_ancora_baixa_o_requisitorio_comprado_com_prazo_real_sem_duplicar_nem_repassar(): void
    {
        [$bank, $client, $category, $receipt] = $this->base();
        $ademar = Broker::create(['name' => 'Ademar', 'status' => true]);
        $writ = Writ::create(['type' => 'precatorio', 'stage' => 'paid', 'assignor_name' => 'Antônio Sérgio Santos Sousa', 'process_number' => '1007715-60.2023.4.01.3701',
            'face_value' => 60000, 'paid_amount' => '40000.00', 'notary_expenses_amount' => '181.88', 'paid_at' => '2026-05-22', 'source_bank_account_id' => $bank->id]);
        WritMovedToPaid::dispatch($writ);
        $bankBefore = $bank->fresh()->balance();

        app(ProcessReceiptService::class)->save($receipt, $this->data($category, [
            ['broker_id' => $ademar->id, 'base' => 'fees', 'mode' => 'fixed', 'value' => '4728.62'],
        ], ['includes_writ' => true, 'writ_id' => $writ->id, 'writ_amount' => '55167.18', 'process_ref' => '1007715-60.2023.4.01.3701']));

        $receipt = $receipt->fresh();
        $parts = $receipt->destinations()->get()->keyBy('kind');
        $this->assertSame('10284.74', $parts['own']->amount);
        $this->assertSame('4728.62', $parts['broker']->amount);
        $this->assertSame('8629.72', $parts['office']->amount);
        $this->assertSame('55167.18', $parts['client']->amount);
        $this->assertSame(0, $parts['client']->fresh()->remainingCents(), 'O crédito comprado não vira valor a repassar ao cliente.');
        $this->assertSame(0.0, $client->fresh()->balance());

        $writ = $writ->fresh();
        $this->assertSame('finalized', $writ->stage);
        $this->assertSame('2026-10-08', $writ->finalized_at->toDateString());
        $this->assertSame('55167.18', $writ->actual_receipt_amount);
        $this->assertSame(139, (int) app(WritProfitabilityCalculator::class)->realized($writ)['days_elapsed']);
        $this->assertSame(14985.30, $writ->actualProfit());
        $this->assertSame('0.00', $writ->transactions()->where('type', 'income')->sole()->amount, 'A entrada do requisitório fica zerada: o dinheiro conta uma vez só.');
        $this->assertSame($bankBefore, $bank->fresh()->balance(), 'A baixa não cria nova entrada no banco.');
        $this->assertSame(38628.38, $bank->fresh()->balance()); // 78.810,26 recebidos − 40.181,88 pagos na aquisição
        $this->assertSame($writ->id, $receipt->processReceipt->writ_id);
        $this->assertSame($writ->id, $receipt->link_writ_id);
        $this->assertSame('1007715-60.2023.4.01.3701', $receipt->link_process);
        $this->assertSame(1, BrokerCommission::where('broker_id', $ademar->id)->count());
    }

    public function test_dois_corretores_percentual_e_fixo_geram_duas_comissoes_e_compensacao_isolada(): void
    {
        [$bank, , $category, $receipt] = $this->base('23643.08');
        $a = Broker::create(['name' => 'Corretor A', 'status' => true]);
        $b = Broker::create(['name' => 'Corretor B', 'status' => true]);
        $advA = app(BrokerAdvanceService::class)->register(['broker_id' => $a->id, 'date' => '2026-10-01', 'amount' => 800, 'bank_account_id' => $bank->id])['advance'];
        $advB = app(BrokerAdvanceService::class)->register(['broker_id' => $b->id, 'date' => '2026-10-01', 'amount' => 600, 'bank_account_id' => $bank->id])['advance'];

        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])
            ->set('form.client_name', 'Cliente')->set('form.fees', '23643.08')->set('form.office_amount', '8629.72')->set('form.category_id', $category->id)
            ->call('addBroker')->call('addBroker')
            ->set('form.brokers.0.broker_id', $a->id)->set('form.brokers.0.mode', 'percent')->set('form.brokers.0.value', '10')
            ->set('form.brokers.1.broker_id', $b->id)->set('form.brokers.1.mode', 'fixed')->set('form.brokers.1.value', '1000')
            ->set('form.brokers.0.advances.'.$advA->id, '500')
            ->assertSee('R$ 2.364,31')->assertSee('R$ 11.649,05')
            ->call('save')->assertHasNoErrors()->assertDispatched('process-receipt-saved');

        $parts = $receipt->fresh()->destinations()->get();
        $this->assertSame('11649.05', $parts->firstWhere('kind', 'own')->amount);
        $this->assertSame(['2364.31', '1000.00'], $parts->where('kind', 'broker')->sortBy('id')->pluck('amount')->values()->all());
        $this->assertSame(1, BrokerCommission::where('broker_id', $a->id)->count());
        $this->assertSame(1, BrokerCommission::where('broker_id', $b->id)->count());
        $this->assertEqualsWithDelta(300.0, $advA->fresh()->remainingBalance(), 0.001);
        $this->assertEqualsWithDelta(600.0, $advB->fresh()->remainingBalance(), 0.001, 'Compensar o corretor A não mexe no adiantamento do B.');
        $shares = ProcessReceiptBroker::orderBy('id')->get();
        $this->assertSame(['percent', 'fixed'], $shares->pluck('mode')->all());
        $this->assertSame('500.00', $shares[0]->compensated_amount);
        $this->assertNotNull($shares[1]->commission_id);
        Volt::test('banking.transactions.index')->set('from', '2026-10-01')->set('vinculo', 'broker:'.$b->id)->assertSee('Corretor B R$ 1.000,00');
    }

    public function test_soma_de_corretores_acima_dos_honorarios_recusa_salvar(): void
    {
        [, , $category, $receipt] = $this->base('23643.08');
        $a = Broker::create(['name' => 'Corretor A', 'status' => true]);
        $b = Broker::create(['name' => 'Corretor B', 'status' => true]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Minha receita não pode ficar negativa');
        try {
            app(ProcessReceiptService::class)->save($receipt, $this->data($category, [
                ['broker_id' => $a->id, 'base' => 'gross', 'mode' => 'percent', 'value' => '70'],
                ['broker_id' => $b->id, 'base' => 'fees', 'mode' => 'fixed', 'value' => '1000'],
            ]));
        } finally {
            $this->assertSame(0, BrokerCommission::count());
            $this->assertSame(0, $receipt->destinations()->count());
        }
    }

    public function test_formato_antigo_de_um_corretor_continua_funcionando(): void
    {
        [, , $category, $receipt] = $this->base('10000.00');
        $broker = Broker::create(['name' => 'Corretor', 'status' => true]);
        app(ProcessReceiptService::class)->save($receipt, ['broker_id' => $broker->id, 'client_name' => 'Maria', 'fees' => '3000', 'fees_mode' => 'amount',
            'commission_mode' => 'amount', 'broker_amount' => '1000', 'office_amount' => '1000', 'category_id' => $category->id, 'expenses' => [], 'advances' => []]);
        $this->assertSame('1000.00', $receipt->destinations()->where('kind', 'own')->sole()->amount);
        $this->assertSame('1000.00', ProcessReceiptBroker::sole()->calculated_amount);
        Volt::test('banking.transactions.process-receipt-form', ['receiptId' => $receipt->id])->assertSet('form.brokers.0.broker_id', $broker->id)->assertSet('form.brokers.0.mode', 'fixed');
    }

    public function test_vinculos_opcionais_salvam_aparecem_e_filtram(): void
    {
        $bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 0, 'status' => true]);
        $cat = Category::create(['name' => 'Venda de bens', 'type' => 'income', 'status' => true]);
        $writ = Writ::create(['type' => 'rpv', 'stage' => 'paid', 'assignor_name' => 'Maria Cedente', 'process_number' => '0001', 'face_value' => 1000, 'paid_amount' => 500]);
        $broker = Broker::create(['name' => 'Joana Corretora', 'status' => true]);
        $person = Contact::create(['name' => 'Pedro Pessoa', 'type' => 'pessoal', 'status' => true]);

        Volt::test('banking.transactions.index')->call('create', 'income')
            ->set('formAmount', '50000')->set('formDescription', 'Venda do carro')->set('formBankAccountId', $bank->id)->set('formCategoryId', $cat->id)
            ->set('linkSearch', 'Maria')->assertSee('Maria Cedente · 0001')
            ->set('formLinkProcess', '123-45')->set('formLinkWritId', $writ->id)->set('formLinkBrokerId', $broker->id)->set('formLinkContactId', $person->id)
            ->call('saveTransaction')->assertHasNoErrors();
        Transaction::create(['type' => 'income', 'amount' => '10', 'date' => now()->toDateString(), 'description' => 'Sem vínculo', 'status' => 'settled', 'bank_account_id' => $bank->id, 'category_id' => $cat->id]);

        $t = Transaction::where('description', 'Venda do carro')->sole();
        $this->assertSame([$writ->id, $broker->id, $person->id, '123-45'], [$t->link_writ_id, $t->link_broker_id, $t->link_contact_id, $t->link_process]);
        $this->assertSame('50000.00', $t->amount);
        $this->assertNull($t->processReceipt, 'Receita comum não obriga o fluxo de processo.');

        foreach (['writ:'.$writ->id, 'broker:'.$broker->id, 'contact:'.$person->id, 'process:123', 'com'] as $filter) {
            Volt::test('banking.transactions.index')->set('vinculo', $filter)->assertSee('Venda do carro')->assertDontSee('Sem vínculo');
        }
        Volt::test('banking.transactions.index')->assertSee('Pessoa:')->assertSee('Pedro Pessoa')->assertSee('Requisitório:');
        Volt::test('banking.transactions.index')->call('edit', $t->id)->assertSet('formLinkWritId', $writ->id)->set('formLinkBrokerId', null)->call('saveTransaction')->assertHasNoErrors();
        $this->assertNull($t->fresh()->link_broker_id);
        $this->assertSame($writ->id, $t->fresh()->link_writ_id);
    }

    public function test_anexo_de_comprovante_privado_com_download_autenticado(): void
    {
        Storage::fake('local');
        $bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 0, 'status' => true]);
        $cat = Category::create(['name' => 'Mercado', 'type' => 'expense', 'status' => true]);

        Volt::test('banking.transactions.index')->call('create', 'expense')
            ->set('formAmount', '10')->set('formDescription', 'Exe')->set('formBankAccountId', $bank->id)->set('formCategoryId', $cat->id)
            ->set('formAttachment', UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))
            ->call('saveTransaction')->assertHasErrors('formAttachment');
        $this->assertSame(0, Transaction::count());

        Volt::test('banking.transactions.index')->call('create', 'expense')
            ->set('formAmount', '10')->set('formDescription', 'Mercado')->set('formBankAccountId', $bank->id)->set('formCategoryId', $cat->id)
            ->set('formAttachment', UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf'))
            ->call('saveTransaction')->assertHasNoErrors();
        $file = TransactionAttachment::sole();
        $this->assertSame('nota.pdf', $file->original_name);
        $this->assertStringStartsWith('comprovantes/', $file->path);
        Storage::disk('local')->assertExists($file->path);

        $this->get(route('banking.transactions.attachments.download', $file))->assertOk()->assertDownload('nota.pdf');
        Volt::test('banking.transactions.index')->assertSee('nota.pdf');

        auth()->logout();
        $this->get(route('banking.transactions.attachments.download', $file))->assertRedirect(route('login'));

        $this->actingAs($this->user);
        Volt::test('banking.transactions.index')->call('deleteAttachment', $file->id);
        $this->assertSame(0, TransactionAttachment::count());
        Storage::disk('local')->assertMissing($file->path);
    }
}
