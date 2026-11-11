<?php

namespace Tests\Feature\Writs;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Confirmar Pagamento: despesas opcionais, sem gravação pela metade, modal de Assinatura e fechar sem preencher. */
class WritPaymentModalTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $itau;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00'));
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();
        $this->itau = BankAccount::create(['name' => 'Itaú', 'initial_balance' => 20000, 'status' => true]);
    }

    private function writ(string $stage, array $extra = []): Writ
    {
        return Writ::create($extra + [
            'type' => 'rpv', 'stage' => $stage, 'assignor_name' => 'Marcos (teste)', 'process_number' => '1007493-29.2022.4.01.3701',
            'face_value' => 11266.33, 'negotiated_amount' => 11266.33, 'proposed_amount' => 8449.75, 'estimated_receipt_amount' => 11266.33,
            'cession_at' => '2025-08-26 14:15:00', 'signature_at' => $stage === 'signature' ? '2025-08-26 15:00:00' : null,
        ]);
    }

    private function writTx(Writ $w)
    {
        return Transaction::where('source_type', Writ::class)->where('source_id', $w->id)->orderBy('id')->get();
    }

    public function test_blank_expenses_are_optional_and_create_only_the_payment(): void
    {
        $w = $this->writ('signature');
        Volt::test('writs.kanban')->call('promptPaidDate', $w->id)
            ->assertSee('Despesas cartorais (opcional)')->assertSee('Outras despesas (opcional)')->assertDontSee('Despesas cartorais *')
            ->assertSet('promptNotaryExpenses', '')->assertSet('promptOtherExpenses', '')
            ->set('promptPaidAmount', '8449.75')->set('promptPaidAt', '2025-08-26')->set('promptSourceBankAccountId', $this->itau->id)
            ->set('promptNotaryExpenses', '')->set('promptOtherExpenses', '')
            ->call('confirmPaidDate')->assertHasNoErrors()->assertSet('showPaidModal', false);
        $w = $w->fresh();
        $this->assertSame(['paid', '8449.75', '0.00', '0.00', '2025-08-26', $this->itau->id], [$w->stage, $w->paid_amount, $w->notary_expenses_amount, $w->other_expenses_amount, $w->paid_at->toDateString(), $w->source_bank_account_id]);
        $tx = $this->writTx($w);
        $this->assertCount(1, $tx, 'só o pagamento ao cedente, nenhuma despesa de zero');
        $this->assertSame(['8449.75', '2025-08-26', $this->itau->id], [$tx[0]->amount, $tx[0]->date->toDateString(), $tx[0]->bank_account_id]);
    }

    public function test_filled_expenses_still_generate_their_entries(): void
    {
        $w = $this->writ('signature');
        Volt::test('writs.kanban')->call('promptPaidDate', $w->id)
            ->set('promptPaidAmount', '1000.00')->set('promptPaidAt', '2026-11-10')->set('promptSourceBankAccountId', $this->itau->id)
            ->set('promptNotaryExpenses', '150.00')->set('promptOtherExpenses', '')
            ->call('confirmPaidDate')->assertHasNoErrors();
        $this->assertSame(['1000.00', '150.00'], $this->writTx($w)->pluck('amount')->all());
    }

    public function test_paid_amount_date_and_account_stay_required(): void
    {
        $w = $this->writ('signature');
        Volt::test('writs.kanban')->call('promptPaidDate', $w->id)
            ->set('promptPaidAmount', '')->set('promptPaidAt', '')->set('promptSourceBankAccountId', null)
            ->call('confirmPaidDate')->assertHasErrors(['promptPaidAmount', 'promptPaidAt', 'promptSourceBankAccountId'])
            ->assertSee('Informe o valor pago ao cedente.')->assertSet('showPaidModal', true);
        $this->assertSame('signature', $w->fresh()->stage);
    }

    public function test_refused_transition_shows_reason_in_modal_and_writes_nothing(): void
    {
        // Caso real: card em Cessão Pendente levado direto a Pagamento. Antes o valor e as despesas eram gravados
        // e a transição falhava escondida atrás do modal.
        $w = $this->writ('pending');
        Volt::test('writs.kanban')->call('promptPaidDate', $w->id)
            ->set('promptPaidAmount', '8449.75')->set('promptPaidAt', '2025-08-26')->set('promptSourceBankAccountId', $this->itau->id)
            ->set('promptNotaryExpenses', '0.01')->set('promptOtherExpenses', '1.00')
            ->call('confirmPaidDate')->assertHasErrors('promptPaid')->assertSee('Mova antes para Assinatura')->assertSet('showPaidModal', true);
        $w = $w->fresh();
        $this->assertSame(['pending', '0.00', '0.00', '0.00'], [$w->stage, $w->paid_amount, $w->notary_expenses_amount, $w->other_expenses_amount]);
        $this->assertCount(0, $this->writTx($w));
    }

    public function test_cancel_closes_the_modal_without_saving(): void
    {
        $w = $this->writ('signature');
        Volt::test('writs.kanban')->call('promptPaidDate', $w->id)->assertSeeHtml('data-paid-close')
            ->set('promptPaidAmount', '5.00')->call('cancelPaidDate')->assertSet('showPaidModal', false)->assertDontSeeHtml('data-paid-modal');
        $this->assertSame(['signature', '0.00'], [$w->fresh()->stage, $w->fresh()->paid_amount]);
    }

    public function test_signature_modal_exists_and_moves_the_card(): void
    {
        $w = $this->writ('pending');
        Volt::test('writs.kanban')->call('promptSignatureDate', $w->id)->assertSeeHtml('data-signature-modal')->assertSee('Data e hora da assinatura')
            ->set('promptSignatureAt', '2025-08-26T15:00')->call('confirmSignatureDate')->assertHasNoErrors()->assertSet('showSignatureModal', false);
        $this->assertSame('signature', $w->fresh()->stage);
        $this->assertSame('2025-08-26 15:00', $w->fresh()->signature_at->format('Y-m-d H:i'));

        $other = $this->writ('pending');
        Volt::test('writs.kanban')->call('promptSignatureDate', $other->id)->call('cancelSignatureDate')->assertSet('showSignatureModal', false);
        $this->assertSame('pending', $other->fresh()->stage);
    }

    public function test_kanban_receives_allowed_moves_and_explains_forbidden_ones(): void
    {
        $w = $this->writ('pending');
        Volt::test('writs.kanban')->assertSeeHtml('\u0022pending\u0022:[\u0022negotiation\u0022,\u0022signature\u0022,\u0022lost\u0022]')
            ->call('moveCard', $w->id, 'paid')->assertSee('Transição não permitida: Cessão Pendente → Pagamento. Mova antes para Assinatura.');
        $this->assertSame('pending', $w->fresh()->stage);
    }

    public function test_writ_page_payment_has_optional_expenses_and_no_partial_write(): void
    {
        $w = $this->writ('signature');
        Volt::test('writs.show', ['writ' => $w])->set('transitionTo', 'paid')->assertSee('Despesas cartorais (opcional)')
            ->set('transition_paid_amount', '8449.75')->set('transition_paid_at', '2025-08-26')->set('transition_source_account', $this->itau->id)
            ->set('transition_notary_expenses', '')->set('transition_other_expenses', '')
            ->call('transition')->assertHasNoErrors();
        $this->assertSame(['paid', '0.00', '0.00'], [$w->fresh()->stage, $w->fresh()->notary_expenses_amount, $w->fresh()->other_expenses_amount]);
        $this->assertCount(1, $this->writTx($w));

        $p = $this->writ('signature');
        Volt::test('writs.show', ['writ' => $p])->set('transitionTo', 'paid')->set('transition_paid_amount', '')->set('transition_paid_at', '')
            ->call('transition')->assertHasErrors(['transition_paid_amount', 'transition_paid_at', 'transition_source_account']);
        $this->assertSame('signature', $p->fresh()->stage);
    }

    public function test_writ_form_accepts_blank_expenses(): void
    {
        $w = $this->writ('negotiation', ['notary_expenses_amount' => 0, 'other_expenses_amount' => 0]);
        Volt::test('writs.form', ['writ' => $w])->set('notary_expenses_amount', '')->set('other_expenses_amount', '')
            ->call('save')->assertHasNoErrors(['notary_expenses_amount', 'other_expenses_amount']);
        $this->assertSame(['0.00', '0.00'], [$w->fresh()->notary_expenses_amount, $w->fresh()->other_expenses_amount]);
    }
}
