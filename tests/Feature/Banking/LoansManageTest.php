<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Loan;
use App\Domains\Banking\Models\LoanSettlement;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\LoanService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Empréstimos: salvar com vencimento vazio, editar, excluir, devolução de vários de uma vez e botão Voltar. */
class LoansManageTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bb;

    private BankAccount $btg;

    private Contact $sandro;

    private Contact $heleno;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 15:00:00');
        $this->actingAs(User::factory()->create());
        $this->bb = BankAccount::create(['name' => 'Banco do Brasil', 'initial_balance' => 50000, 'status' => true]);
        $this->btg = BankAccount::create(['name' => 'BTG', 'initial_balance' => 0, 'status' => true]);
        $this->sandro = Contact::create(['name' => 'Sandro Mota', 'type' => 'pessoal', 'status' => true]);
        $this->heleno = Contact::create(['name' => 'Heleno Mota', 'type' => 'pessoal', 'status' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loan(Contact $who, string $principal, string $date = '2026-10-30', array $extra = []): Loan
    {
        return app(LoanService::class)->create($extra + [
            'contact_id' => $who->id, 'bank_account_id' => $this->bb->id, 'description' => 'Multa S10', 'principal' => $principal,
            'date' => $date, 'installments' => 1, 'first_due_date' => '', 'interest_rate_am' => '0', 'notes' => '', 'request_key' => (string) Str::uuid(),
        ]);
    }

    private function balance(BankAccount $a): float
    {
        return round((float) $a->fresh()->balance(), 2);
    }

    public function test_save_with_empty_due_date_stores_null_like_the_sandro_case(): void
    {
        // Mesmo preenchimento do Cassio: 1 parcela, vencimento vazio, juros 0, sem observações nem comprovante.
        Volt::test('banking.loans.index')->call('startCreate')
            ->set('form.contact_id', $this->sandro->id)->set('form.bank_account_id', $this->bb->id)
            ->set('form.description', 'Compra GWM Poer')->set('form.principal', '27240.00')->set('form.date', '2026-10-14')
            ->set('form.installments', 1)->set('form.first_due_date', '')->set('form.interest_rate_am', '0')->set('form.notes', '')
            ->call('save')->assertHasNoErrors()->assertSet('creating', false);
        $loan = Loan::firstOrFail();
        // No MySQL estrito, '' em coluna DATE dava "Incorrect date value" (tela de erro). Agora vai NULL.
        $this->assertNull($loan->getRawOriginal('first_due_date'));
        $this->assertNull($loan->getRawOriginal('notes'));
        $this->assertSame('27240.00', $loan->disbursement->amount);
        $this->assertSame(50000 - 27240.0, $this->balance($this->bb));
    }

    public function test_edit_without_returns_updates_everything_and_the_linked_transaction(): void
    {
        $loan = $this->loan($this->sandro, '1000.00');
        Volt::test('banking.loans.index')->call('open', $loan->id)->assertSeeHtml('data-loan-edit')->assertSeeHtml('data-loan-delete')
            ->call('startEdit')->assertSet('editingId', $loan->id)->assertSet('form.principal', '1000.00')->assertSee('Editar empréstimo')
            ->set('form.contact_id', $this->heleno->id)->set('form.bank_account_id', $this->btg->id)->set('form.description', 'Multas')
            ->set('form.principal', '1500.50')->set('form.date', '2026-10-20')->set('form.installments', 3)->set('form.first_due_date', '2026-11-20')
            ->set('form.interest_rate_am', '1,5')->set('form.notes', 'acerto')
            ->call('save')->assertHasNoErrors()->assertSet('creating', false)->assertSet('selectedId', $loan->id);
        $loan = $loan->fresh(['installments', 'disbursement']);
        $this->assertSame($this->heleno->id, $loan->contact_id);
        $this->assertSame('1500.50', $loan->principal);
        $this->assertSame(3, $loan->installment_count);
        $this->assertSame(['500.16', '500.16', '500.18'], $loan->installments->pluck('amount')->all());
        $this->assertSame('2027-01-20', $loan->installments->last()->due_date->toDateString());
        $this->assertSame(1.5, $loan->interest_rate_am);
        $out = $loan->disbursement;
        $this->assertSame(['1500.50', '2026-10-20', $this->btg->id, 'Empréstimo concedido · Heleno Mota · Multas'], [$out->amount, $out->date->toDateString(), $out->bank_account_id, $out->description]);
        $this->assertSame(1, Transaction::where('source_type', Loan::class)->count());
        $this->assertSame(50000.0, $this->balance($this->bb));
        $this->assertSame(-1500.5, $this->balance($this->btg));
    }

    public function test_edit_with_returns_only_allows_description_and_notes(): void
    {
        $loan = $this->loan($this->sandro, '1000.00');
        app(LoanService::class)->receive($loan, '200.00', '2026-11-01', $this->bb->id, (string) Str::uuid());
        Volt::test('banking.loans.index')->call('open', $loan->id)->assertSeeHtml('data-loan-locked-note')->assertSeeHtml('data-loan-delete-blocked')
            ->call('startEdit')->assertSeeHtml('data-edit-locked')
            ->set('form.principal', '900.00')->call('save')->assertHasErrors('loan')->assertSee('estorne antes as devoluções');
        $this->assertSame('1000.00', $loan->fresh()->principal);
        Volt::test('banking.loans.index')->call('open', $loan->id)->call('startEdit')->set('form.description', 'Carro')->set('form.notes', 'ok')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['Carro', 'ok'], [$loan->fresh()->description, $loan->fresh()->notes]);
        $this->assertSame('Empréstimo concedido · Sandro Mota · Carro', $loan->fresh()->disbursement->description);
    }

    public function test_delete_asks_and_undoes_the_outflow_but_is_blocked_with_returns(): void
    {
        $loan = $this->loan($this->sandro, '300.00');
        $this->assertSame(49700.0, $this->balance($this->bb));
        Volt::test('banking.loans.index')->call('open', $loan->id)->assertSeeHtml('wire:confirm="Excluir este empréstimo?')
            ->call('detach')->assertSet('selectedId', null)->assertSee('Empréstimo excluído');
        $this->assertNull(Loan::find($loan->id));
        $this->assertSame(50000.0, $this->balance($this->bb));

        $other = $this->loan($this->sandro, '300.00');
        app(LoanService::class)->receive($other, '100.00', '2026-11-01', $this->bb->id, (string) Str::uuid());
        Volt::test('banking.loans.index')->call('open', $other->id)->call('detach')->assertHasErrors('loan');
        $this->assertNotNull(Loan::find($other->id));
    }

    public function test_one_payment_settles_the_four_heleno_loans_in_a_single_entry(): void
    {
        $loans = collect(['1467.35', '1467.35', '197.18', '156.18'])->map(fn ($v) => $this->loan($this->heleno, $v));
        $before = $this->balance($this->bb);
        $tx = app(LoanService::class)->receiveBatch($this->heleno->id, '3288.06', '2026-11-09', $this->bb->id, (string) Str::uuid(), $loans->pluck('id')->all());
        $this->assertSame('3288.06', $tx->amount);
        $this->assertSame('Devolução de empréstimos · Heleno Mota · 4 empréstimos', $tx->description);
        $this->assertNull($tx->category_id);
        $this->assertSame(1, Transaction::where('type', 'income')->count(), 'um único lançamento de entrada');
        $this->assertSame(4, LoanSettlement::where('transaction_id', $tx->id)->distinct('loan_id')->count('loan_id'));
        foreach ($loans as $loan) {
            $this->assertSame(0, $loan->fresh(['installments.settlements', 'settlements'])->remainingCents());
        }
        $this->assertSame(round($before + 3288.06, 2), $this->balance($this->bb), 'dinheiro entra uma vez só');
        $totals = app(OwnFinancialService::class)->totals(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertEquals(0, $totals['income'], 'principal recuperado não é receita');
    }

    public function test_auto_distribution_goes_oldest_first_and_manual_mode_validates_totals(): void
    {
        $old = $this->loan($this->heleno, '1000.00', '2026-10-01');
        $new = $this->loan($this->heleno, '500.00', '2026-10-20');
        $service = app(LoanService::class);
        $plan = $service->planBatch($this->heleno->id, 120000, '2026-11-09', [$new->id, $old->id]);
        $this->assertSame([[$old->id, 100000, 0], [$new->id, 20000, 30000]], collect($plan['rows'])->map(fn ($r) => [$r['loan']->id, $r['applied'], $r['remaining']])->all());

        try {
            $service->receiveBatch($this->heleno->id, '2000.00', '2026-11-09', $this->bb->id, (string) Str::uuid(), [$old->id, $new->id]);
            $this->fail('deveria recusar sobra');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Sobram R$ 500,00', $e->getMessage());
        }
        try {
            $service->receiveBatch($this->heleno->id, '300.00', '2026-11-09', $this->bb->id, (string) Str::uuid(), [$old->id, $new->id], [$old->id => '200.00', $new->id => '200.00']);
            $this->fail('deveria recusar soma maior');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('passa do valor recebido', $e->getMessage());
        }
        $service->receiveBatch($this->heleno->id, '300.00', '2026-11-09', $this->bb->id, (string) Str::uuid(), [$old->id, $new->id], [$old->id => '', $new->id => '300.00']);
        $this->assertSame(100000, $old->fresh(['installments.settlements', 'settlements'])->remainingCents());
        $this->assertSame(20000, $new->fresh(['installments.settlements', 'settlements'])->remainingCents());
        $this->assertSame(0, Transaction::where('type', 'income')->whereNull('deleted_at')->where('amount', '!=', 300)->count());
    }

    public function test_interest_only_becomes_income_when_received_and_joint_undo_reverts_all(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');
        $a = $this->loan($this->heleno, '1000.00', '2026-01-01', ['interest_rate_am' => '2', 'first_due_date' => '2026-04-01']);
        $b = $this->loan($this->heleno, '500.00', '2026-01-01');
        Carbon::setTestNow('2026-04-01 12:00:00');
        $key = (string) Str::uuid();
        $service = app(LoanService::class);
        $tx = $service->receiveBatch($this->heleno->id, '1560.00', '2026-04-01', $this->bb->id, $key, [$a->id, $b->id]);
        $this->assertSame(Category::loanInterestCategoryId(), (int) $tx->category_id);
        $this->assertSame('60.00', LoanSettlement::where('loan_id', $a->id)->sum('interest_amount') ? number_format((float) LoanSettlement::where('loan_id', $a->id)->sum('interest_amount'), 2, '.', '') : null);
        $totals = app(OwnFinancialService::class)->totals(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'));
        $this->assertEquals(60, $totals['income'], 'só os juros viram receita');
        // mesma chave = não duplica
        $service->receiveBatch($this->heleno->id, '1560.00', '2026-04-01', $this->bb->id, $key, [$a->id, $b->id]);
        $this->assertSame(1, Transaction::where('type', 'income')->count());

        Volt::test('banking.loans.index')->call('open', $b->id)->assertSeeHtml('data-joint-return')->assertSee('Devolução conjunta (2 empréstimos)')
            ->call('undo', $key)->assertHasNoErrors();
        $this->assertSame(0, LoanSettlement::count());
        $this->assertSame(0, Transaction::where('type', 'income')->count());
        $this->assertSame(156000, $a->fresh(['installments.settlements', 'settlements'])->remainingCents() + $b->fresh(['installments.settlements', 'settlements'])->remainingCents());
    }

    public function test_register_return_screen_previews_what_stays_open_and_saves(): void
    {
        $l1 = $this->loan($this->heleno, '1467.35', '2026-10-01');
        $l2 = $this->loan($this->heleno, '156.18', '2026-10-02');
        $this->loan($this->sandro, '50.00');
        Volt::test('banking.loans.index')->call('startReturn', $this->heleno->id)->assertSet('returning', true)
            ->assertSet('ret.loans', [$l1->id => true, $l2->id => true])->assertSee('Distribuir automaticamente')
            ->set('ret.bank_account_id', $this->btg->id)->set('ret.date', '2026-11-09')->set('ret.amount', '1500.00')
            ->assertSeeHtml('data-return-remaining')->assertSee('R$ 123,53')
            ->set('ret.amount', '1700.00')->assertSee('Sobram R$ 76,47')->call('registerReturn')->assertHasErrors('ret')
            ->set('ret.amount', '1623.53')->call('registerReturn')->assertHasNoErrors()->assertSet('returning', false)
            ->assertSee('Devolução registrada em um único lançamento');
        $this->assertSame(162353, (int) round($this->balance($this->btg) * 100));
        $this->assertSame(1, Transaction::where('type', 'income')->count());
    }

    public function test_back_buttons_on_loans_and_receivables(): void
    {
        $loan = $this->loan($this->sandro, '100.00');
        Volt::test('banking.loans.index')->assertSeeHtml('data-back-button')->assertSee('Voltar')
            ->call('open', $loan->id)->assertSee('Voltar à lista')->call('closeDetail')->assertSet('selectedId', null);
        Volt::test('banking.receivables.index')->assertSeeHtml('data-back-button');
        $this->get(route('banking.loans.index'))->assertOk()->assertSee('Registrar devolução')->assertSee('Voltar');
        $this->get(route('banking.receivables.index'))->assertOk()->assertSee('Voltar');
    }
}
