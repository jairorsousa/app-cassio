<?php

namespace Tests\Feature\Dashboard;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Models\CreditCardInvoice;
use App\Domains\Banking\Models\RecurringTransaction;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\CashflowReportService;
use App\Domains\Banking\Services\CashPositionService;
use App\Domains\Banking\Services\LoanService;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Dashboard\Services\DashboardWidgetService;
use App\Domains\Dashboard\Support\DashboardPreferences;
use App\Domains\Dashboard\Support\ReportPeriod;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class Etapa7DashboardFluxoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BankAccount $bank;

    private Category $food;

    private Category $fees;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Livewire::withoutLazyLoading();
        $this->bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 10000, 'status' => true]);
        $this->food = Category::create(['name' => 'Alimentação', 'type' => 'expense', 'status' => true]);
        $this->fees = Category::create(['name' => 'Honorários', 'type' => 'income', 'status' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(string $type, float $amount, string $date, array $extra = []): Transaction
    {
        return Transaction::create(array_merge(['type' => $type, 'amount' => $amount, 'date' => $date, 'description' => $type.' '.$amount, 'status' => 'settled', 'bank_account_id' => $this->bank->id, 'category_id' => $type === 'income' ? $this->fees->id : $this->food->id], $extra));
    }

    public function test_preferences_persist_per_user_and_survive_reload(): void
    {
        $c = Volt::test('dashboard.index');
        $c->call('toggleWidget', 'loans')->call('moveWidget', 'own_result', -1)->call('setChartType', 'income_expense', 'area')->set('preset', 'last_6');

        $saved = DashboardPreferences::for($this->user->fresh());
        $this->assertContains('loans', $saved['hidden']);
        $this->assertSame('own_result', $saved['order'][0]);
        $this->assertSame('area', $saved['charts']['income_expense']);
        $this->assertSame('last_6', $saved['period']['preset']);

        // "Reload": novo componente lê as preferências gravadas.
        $this->actingAs($this->user->fresh());
        $reloaded = Livewire::withoutLazyLoading()->test("dashboard.index");
        $this->assertSame('last_6', $reloaded->get('preset'));
        $this->assertSame('own_result', $reloaded->get('prefs')['order'][0]);

        // Outro usuário não herda.
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->assertSame(DashboardPreferences::defaults()['order'], Livewire::withoutLazyLoading()->test("dashboard.index")->get("prefs")['order']);
        $this->assertNull($other->fresh()->dashboard_preferences);
    }

    public function test_drag_reorder_and_reset_are_non_destructive(): void
    {
        $this->tx('income', 500, '2026-11-02');
        $before = Transaction::count();
        $order = array_reverse(array_keys(DashboardPreferences::WIDGETS));
        $c = Volt::test('dashboard.index')->call('reorder', $order);
        $this->assertSame($order, DashboardPreferences::for($this->user->fresh())['order']);
        $c->set('preset', 'last_3')->call('resetDefaults');
        $prefs = DashboardPreferences::for($this->user->fresh());
        $this->assertSame(DashboardPreferences::defaults()['order'], $prefs['order']);
        $this->assertSame('last_3', $prefs['period']['preset']); // período preservado
        $this->assertSame($before, Transaction::count());
    }

    public function test_drag_and_drop_moves_widget_to_target_and_persists(): void
    {
        $before = Transaction::count();
        $c = Livewire::withoutLazyLoading()->test('dashboard.index')->call('toggleCustomize');
        $c->assertSeeHtml('data-drag-handle="loans"')->assertSeeHtml('draggable="true"')->assertSeeHtml('moveWidgetTo(from');
        $order = DashboardPreferences::defaults()['order'];
        // Arrastar "loans" para cima, sobre "cash_position" (primeiro).
        $c->call('moveWidgetTo', 'loans', 'cash_position');
        $saved = DashboardPreferences::for($this->user->fresh())['order'];
        $this->assertSame('loans', $saved[0]);
        $this->assertSame('cash_position', $saved[1]);
        // Arrastar "cash_position" para baixo, sobre "invoices": fica no lugar dele.
        $targetIndex = array_search('invoices', $saved, true);
        $c->call('moveWidgetTo', 'cash_position', 'invoices');
        $saved = DashboardPreferences::for($this->user->fresh())['order'];
        $this->assertSame($targetIndex, array_search('cash_position', $saved, true));
        $this->assertCount(count($order), $saved);
        // Alvos inválidos não mexem na ordem.
        $c->call('moveWidgetTo', 'nao_existe', 'loans')->call('moveWidgetTo', 'loans', 'loans');
        $this->assertSame($saved, DashboardPreferences::for($this->user->fresh())['order']);
        // Setas continuam funcionando junto.
        $c->call('moveWidget', 'loans', 1);
        $this->assertSame('loans', DashboardPreferences::for($this->user->fresh())['order'][1]);
        $this->assertSame($before, Transaction::count());
    }

    public function test_expired_session_is_419_and_the_app_warns_instead_of_failing_silently(): void
    {
        // CSRF continua ativo: POST do Livewire sem token válido devolve 419 (nada é salvo).
        $this->app['env'] = 'production';
        $before = $this->user->fresh()->dashboard_preferences;
        $response = $this->withMiddleware()->withHeaders(['X-Livewire' => '1'])->postJson('/livewire/update', ['components' => []]);
        $this->assertSame(419, $response->status());
        $this->assertSame($before, $this->user->fresh()->dashboard_preferences);

        $js = file_get_contents(resource_path('js/session-expired.js'));
        $this->assertStringContainsString('Sua sessão expirou. Recarregue a página para acessar novamente antes de salvar.', $js);
        $this->assertStringContainsString('status === 419', $js);
        $this->assertStringContainsString('preventDefault()', $js);
        $this->assertStringContainsString('/dashboard?personalizar=1', $js);
        $this->assertStringContainsString("getElementById('session-expired-alert')", $js); // um aviso só, sem loop
        $this->assertStringNotContainsString('location.reload', $js);
        $this->assertStringContainsString('registerSessionExpiredHandler(Livewire)', file_get_contents(resource_path('js/app.js')));

        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $built = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));
        $this->assertStringContainsString('Sua sessão expirou', $built);
    }

    public function test_reload_target_opens_dashboard_in_customize_mode(): void
    {
        Livewire::withQueryParams(['personalizar' => '1'])->withoutLazyLoading()->test('dashboard.index')
            ->assertSet('customizing', true)->assertSee('Restaurar padrão');
        Livewire::withQueryParams([])->withoutLazyLoading()->test('dashboard.index')->assertSet('customizing', false);
        $this->get('/dashboard?personalizar=1')->assertOk();
    }

    public function test_dashboard_renders_widgets_hides_and_keeps_launch_shortcut(): void
    {
        $this->tx('income', 1200, '2026-11-02');
        $c = Volt::test('dashboard.index');
        $c->assertSee('Saldo bruto, terceiros e caixa próprio')->assertSee('Empréstimos em aberto')->assertSee('Posição em 05/11/2026')
            ->assertSee('novo=expense', false);
        $c->call('toggleWidget', 'loans')->assertDontSee('Abrir Empréstimos');
        $c->call('toggleCustomize')->assertSee('Restaurar padrão')->assertSee('Mover Saldos por conta para cima');
        $this->get('/dashboard')->assertOk();
    }

    public function test_flows_respect_period_and_positions_use_last_day(): void
    {
        $this->tx('income', 1000, '2026-09-10');
        $this->tx('expense', 300, '2026-10-15');
        $this->tx('income', 200, '2026-11-03');
        $svc = app(DashboardWidgetService::class);

        $oct = $svc->build(ReportPeriod::fromInput('custom', '2026-10-01', '2026-10-31'));
        $this->assertEquals(0, $oct['flows']['income']);
        $this->assertEquals(300, $oct['flows']['expense']);
        $this->assertSame('2026-10-31', $oct['period']['position_date']);
        $this->assertEquals(10700, $oct['cash']['accounts_balance']); // 10000 + 1000 - 300, sem a entrada de novembro

        $three = $svc->build(ReportPeriod::fromInput('last_3'));
        $this->assertEquals(1200, $three['flows']['income']);
        $this->assertEquals(10900, $three['cash']['own_balance']);
        $this->assertCount(3, $three['flows']['months']);
        $this->assertEquals(app(CashPositionService::class)->summarize()['own_balance'], $three['cash']['own_balance']);
    }

    public function test_writs_upcoming_never_include_monitoring(): void
    {
        Writ::create(['type' => 'rpv', 'stage' => 'paid', 'face_value' => 3000, 'paid_amount' => 1000, 'estimated_receipt_amount' => 2000, 'paid_at' => '2026-09-01', 'estimated_months' => 3]);
        Writ::create(['type' => 'rpv', 'stage' => 'monitoring', 'face_value' => 9000, 'paid_amount' => 0, 'estimated_receipt_amount' => 9000, 'paid_at' => '2026-09-01', 'estimated_months' => 3]);
        $data = app(DashboardWidgetService::class)->writsUpcoming(Carbon::parse('2026-11-05'));
        $this->assertEquals(2000, $data['total']);
        $this->assertSame(1, $data['months'][1]['count']); // dezembro
        $this->assertEquals(1000, $data['months'][1]['profit']);

        $fc = app(CashflowReportService::class)->forecast(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'), ['account_id' => null, 'category_id' => null, 'person' => null], Carbon::parse('2026-11-05'));
        $writItems = array_values(array_filter($fc['items'], fn ($i) => $i['source'] === 'Requisitório'));
        $this->assertCount(1, $writItems);
        $this->assertEquals(2000, $writItems[0]['amount']);
        $this->assertEquals(1000, $writItems[0]['profit']);

        // Previsão já vencida não entra no previsto nem na projeção, mas é informada.
        Writ::create(['type' => 'rpv', 'stage' => 'awaiting_receipt', 'face_value' => 5000, 'paid_amount' => 1000, 'estimated_receipt_amount' => 5000, 'paid_at' => '2025-01-01', 'estimated_months' => 6]);
        $proj = app(CashflowReportService::class)->projection(3);
        $this->assertSame(1, $proj['overdue_writs']['count']);
        $this->assertEquals(5000, $proj['overdue_writs']['amount']);
        $this->assertEquals(2000, array_sum(array_column($proj['rows'], 'income')));
    }

    public function test_cashflow_filters_by_account_category_and_person(): void
    {
        $other = BankAccount::create(['name' => 'Itaú', 'initial_balance' => 0, 'status' => true]);
        $contact = Contact::create(['name' => 'Ana', 'type' => 'pessoal', 'status' => true]);
        $this->tx('expense', 100, '2026-11-02');
        $this->tx('expense', 40, '2026-11-02', ['bank_account_id' => $other->id, 'link_contact_id' => $contact->id]);
        $this->tx('income', 500, '2026-11-03');
        $svc = app(CashflowReportService::class);
        $period = ReportPeriod::month('2026-11');

        $all = $svc->build($period);
        $this->assertEquals(140, $all['realized']['own_expense']);
        $this->assertEquals(app(OwnFinancialService::class)->totals($period->start, $period->end)['result'], $all['realized']['result']);

        $this->assertEquals(40, $svc->build($period, ['account_id' => $other->id])['realized']['own_expense']);
        $cat = $svc->build($period, ['category_id' => $this->fees->id])['realized'];
        $this->assertEquals(500, $cat['own_income']);
        $this->assertEquals(0, $cat['own_expense']);
        $this->assertSame(['Honorários'], array_keys($cat['by_category']->all()));
        $this->assertEquals(40, $svc->build($period, ['person' => 'contato:'.$contact->id])['realized']['own_expense']);
        $this->assertNull($svc->cleanFilters(['person' => "x' or 1=1"])['person']);
    }

    public function test_card_regime_and_comparison_with_previous_period(): void
    {
        $card = CreditCard::create(['name' => 'Nubank', 'closing_day' => 1, 'due_day' => 10, 'status' => true, 'holder_type' => 'luciane']);
        $invoice = CreditCardInvoice::create(['credit_card_id' => $card->id, 'reference_month' => '2026-10', 'total' => 300, 'paid_amount' => 300, 'due_date' => '2026-11-10', 'closing_date' => '2026-11-01', 'status' => 'paid']);
        Transaction::create(['type' => 'expense', 'amount' => 300, 'date' => '2026-10-20', 'description' => 'Mercado', 'status' => 'settled', 'credit_card_id' => $card->id, 'credit_card_invoice_id' => $invoice->id, 'category_id' => $this->food->id]);
        $this->tx('invoice_payment', 300, '2026-11-04', ['credit_card_invoice_id' => $invoice->id, 'category_id' => null]);
        $svc = app(CashflowReportService::class);

        $cashNov = $svc->build(ReportPeriod::month('2026-11'), ['regime' => 'cash']);
        $this->assertEquals(300, $cashNov['realized']['own_expense']);
        $this->assertEquals(0, $cashNov['previous']['own_expense']);
        $this->assertNull($cashNov['comparison']['expense']['pct']); // divisão por zero tratada
        $accrualNov = $svc->build(ReportPeriod::month('2026-11'), ['regime' => 'accrual']);
        $this->assertEquals(0, $accrualNov['realized']['own_expense']);
        $this->assertEquals(300, $accrualNov['previous']['own_expense']);
        $this->assertEquals(-100.0, $accrualNov['comparison']['expense']['pct']);
        $this->assertEquals(300, $svc->build(ReportPeriod::month('2026-10'), ['regime' => 'accrual', 'person' => 'luciane'])['realized']['own_expense']);
    }

    public function test_loan_principal_out_of_result_and_interest_in(): void
    {
        $contact = Contact::create(['name' => 'Bia', 'type' => 'pessoal', 'status' => true]);
        $svc = app(LoanService::class);
        Carbon::setTestNow('2026-08-01 12:00:00');
        $loan = $svc->create(['contact_id' => $contact->id, 'bank_account_id' => $this->bank->id, 'description' => 'Emp', 'principal' => '1000.00', 'date' => '2026-08-01', 'installments' => 1, 'first_due_date' => '2026-11-01', 'interest_rate_am' => 2, 'request_key' => (string) Str::uuid()]);
        Carbon::setTestNow('2026-11-01 12:00:00');
        $svc->receive($loan, '500.00', '2026-11-01', $this->bank->id, (string) Str::uuid());
        Carbon::setTestNow('2026-11-05 12:00:00');

        $report = app(CashflowReportService::class)->build(ReportPeriod::fromInput('custom', '2026-08-01', '2026-11-05'));
        $this->assertEquals(0, $report['realized']['own_expense']);
        $interest = (float) $loan->fresh('settlements')->settlements->sum('interest_amount');
        $this->assertGreaterThan(0, $interest);
        $this->assertEquals($interest, $report['realized']['own_income']);

        $loans = app(DashboardWidgetService::class)->loansAt('2026-11-05');
        $this->assertSame(1, $loans['count']);
        $this->assertGreaterThan(0, $loans['principal']);
    }

    public function test_forecast_and_projection_include_pending_invoices_and_recurring(): void
    {
        $this->tx('expense', 250, '2026-11-20', ['status' => 'pending']);
        $this->tx('income', 800, '2026-12-05', ['status' => 'pending']);
        $card = CreditCard::create(['name' => 'BTG', 'closing_day' => 1, 'due_day' => 10, 'status' => true]);
        CreditCardInvoice::create(['credit_card_id' => $card->id, 'reference_month' => '2026-11', 'total' => 194.46, 'paid_amount' => 0, 'due_date' => '2026-12-10', 'closing_date' => '2026-12-01', 'status' => 'open']);
        RecurringTransaction::create(['type' => 'expense', 'description' => 'Aluguel', 'amount' => 1000, 'category_id' => $this->food->id, 'bank_account_id' => $this->bank->id, 'frequency' => 'monthly', 'day_of_month' => 15, 'start_date' => '2026-01-15', 'last_run_date' => '2026-10-15', 'status' => 'active']);

        $svc = app(CashflowReportService::class);
        $nov = $svc->forecast(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), $svc->cleanFilters([]));
        $this->assertEquals(1250, $nov['expense']); // pendente 250 + aluguel 15/11 (ainda não gerado)

        $proj = $svc->projection(3);
        $this->assertCount(3, $proj['rows']);
        $this->assertEquals(10000, $proj['start_balance']);
        $this->assertEquals(8750, $proj['rows'][0]['balance']);
        $this->assertEqualsWithDelta(8750 + 800 - 194.46 - 1000, $proj['rows'][1]['balance'], 0.01);
        $this->assertCount(12, $svc->projection(12)['rows']);
        $this->assertCount(3, $svc->projection(7)['rows']);
    }

    public function test_dashboard_invoices_and_receivable_positions(): void
    {
        $card = CreditCard::create(['name' => 'BTG', 'closing_day' => 1, 'due_day' => 10, 'status' => true]);
        CreditCardInvoice::create(['credit_card_id' => $card->id, 'reference_month' => '2026-10', 'total' => 194.46, 'paid_amount' => 0, 'due_date' => '2026-11-01', 'closing_date' => '2026-10-25', 'status' => 'closed']);
        CreditCardInvoice::create(['credit_card_id' => $card->id, 'reference_month' => '2026-11', 'total' => 500, 'paid_amount' => 0, 'due_date' => '2026-12-10', 'closing_date' => '2026-12-01', 'status' => 'open']);
        $inv = app(DashboardWidgetService::class)->invoicesAt(Carbon::parse('2026-11-05'), true);
        $this->assertEquals(194.46, $inv['overdue_total']);
        $this->assertEquals(500, $inv['upcoming_total']);
    }

    public function test_cashflow_page_and_authenticated_exports(): void
    {
        $this->tx('income', 1234.56, '2026-11-03', ['description' => 'Honorário São João']);
        Volt::test('banking.reports.cashflow')->assertSee('Realizado x previsto')->assertSee('Projeção de caixa')
            ->set('horizon', 6)->assertSee('6 meses')->set('start', '2026-11-01')->set('end', '2026-11-04')->assertSee('01/11/2026 a 04/11/2026');

        $csv = $this->get('/banking/reports/cashflow/export/csv?de=2026-11-01&ate=2026-11-30');
        $csv->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());
        $this->assertStringContainsString('1234,56', $csv->getContent());
        $this->assertStringContainsString('horário de Fortaleza', $csv->getContent());
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));

        $pdf = $this->get('/banking/reports/cashflow/export/pdf?month=2026-11');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));

        $this->get('/banking/reports/cashflow/export/xls')->assertNotFound();
        $this->get('/banking/reports/cashflow/export/csv?conta=99999')->assertSessionHasErrors('conta');

        auth()->logout();
        $this->get('/banking/reports/cashflow/export/csv')->assertRedirect('/login');
    }
}
