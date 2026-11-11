<?php

namespace Tests\Feature;

use App\Domains\Banking\Models\CreditCard;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Services\DivisionService;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Botão "Voltar" padrão (seta + texto, 44px) no topo das telas de detalhe e secundárias, apontando para a tela-mãe. */
class BackButtonsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Cassio Mota']);
        $this->admin->forceFill(['is_admin' => true, 'status' => 'active', 'modules' => array_keys(\App\Support\Access::MODULES)])->save();
        $this->actingAs($this->admin);
    }

    private function assertBackTo(string $url, string $parent, string $label = 'Voltar'): void
    {
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote($parent, '#').'"[\s\S]{0,900}?data-back-button>\s*<span[^>]*>arrow_back</span>'.preg_quote($label, '#').'#u', $html, "Sem Voltar para $parent em $url");
        $this->assertStringContainsString('h-11 min-h-[44px]', $html);
    }

    private function assertNoBack(string $url): void
    {
        $this->assertStringNotContainsString('data-back-button', $this->get($url)->assertOk()->getContent(), "Não deveria ter Voltar em $url");
    }

    public function test_divisions_group_goes_back_to_divisions(): void
    {
        $g = app(DivisionService::class)->createGroup($this->admin, ['name' => 'Viagem', 'type' => 'amigos', 'requires_approval' => false]);
        $this->assertBackTo(route('divisions.show', $g), route('divisions.index'));
        $html = $this->get(route('divisions.show', $g))->getContent();
        $this->assertStringNotContainsString('‹ Divisões', $html, 'o link pequeno antigo saiu');
        $this->assertNoBack(route('divisions.index'));
    }

    public function test_investments_screens_go_back_to_overview(): void
    {
        foreach (['investments.positions', 'investments.operations.index', 'investments.dividends.index', 'investments.assets.index', 'investments.reports', 'investments.b3', 'investments.statements', 'bens.index'] as $r) {
            $this->assertBackTo(route($r), route('investments.dashboard'));
        }
        $this->assertNoBack(route('investments.dashboard'));
    }

    public function test_banking_screens(): void
    {
        foreach (['banking.accounts.index', 'banking.categories.index', 'banking.cards.index', 'banking.transactions.index', 'banking.recurring.index', 'banking.reports.cashflow', 'banking.loans.index', 'banking.receivables.index'] as $r) {
            $this->assertBackTo(route($r), route('banking.dashboard'));
        }
        $card = CreditCard::create(['name' => 'Visa', 'limit' => 5000, 'closing_day' => 25, 'due_day' => 5]);
        $this->assertBackTo(route('banking.cards.invoices', $card), route('banking.cards.index'));
        $this->assertBackTo(route('banking.transactions.statement-import'), route('banking.transactions.index'));
        $this->assertSame(1, substr_count($this->get(route('banking.loans.index'))->getContent(), 'data-back-button'), 'sem botão duplicado');
        $this->assertNoBack(route('banking.dashboard'));
    }

    public function test_writs_screens(): void
    {
        $w = Writ::create(['type' => 'rpv', 'stage' => 'negotiation', 'assignor_name' => 'Cedente', 'face_value' => 1000, 'estimated_receipt_amount' => 1000]);
        $this->assertBackTo(route('writs.show', $w), route('writs.kanban'));
        $this->assertBackTo(route('writs.edit', $w), route('writs.show', $w));
        $this->assertBackTo(route('writs.create'), route('writs.kanban'));
        $this->assertBackTo(route('writs.reports'), route('writs.kanban'));
    }

    public function test_partnership_screens(): void
    {
        $p = Partnership::create(['name' => 'Escritório', 'participation_percentage' => 30, 'joined_at' => '2026-01-01', 'status' => true]);
        $this->assertBackTo(route('partnership.show', $p), route('partnership.index'));
        foreach (['partnership.contributions.index', 'partnership.expenses.index', 'partnership.distributions.index', 'partnership.reports', 'partnership.edit'] as $r) {
            $this->assertBackTo(route($r, $p), route('partnership.show', $p));
        }
        $this->assertBackTo(route('partnership.create'), route('partnership.index'));
    }

    public function test_brokers_and_contacts_screens(): void
    {
        $b = Contact::create(['name' => 'Corretor X', 'type' => 'corretor', 'status' => true]);
        $this->assertBackTo(route('brokers.show', $b), route('brokers.index'));
        $this->assertBackTo(route('brokers.reports'), route('brokers.index'));
        $this->assertBackTo(route('brokers.tipos-caso.index'), route('brokers.index'));
        $this->assertBackTo(route('brokers.advances.index', $b), route('brokers.show', $b), 'Voltar ao corretor');
        $this->assertBackTo(route('brokers.commissions.index', $b), route('brokers.show', $b), 'Voltar ao corretor');
        $this->assertBackTo(route('brokers.advances.create', $b), route('brokers.advances.index', $b));
        $this->assertBackTo(route('brokers.commissions.create', $b), route('brokers.commissions.index', $b));
        $this->assertBackTo(route('brokers.edit', $b), route('brokers.show', $b));
        $this->assertNoBack(route('brokers.index'));

        $c = Contact::create(['name' => 'Ana', 'type' => 'pessoal', 'status' => true]);
        $this->assertBackTo(route('contacts.show', $c), route('contacts.index'));
        $this->assertBackTo(route('contacts.edit', $c), route('contacts.show', $c));
        $this->assertBackTo(route('contacts.create'), route('contacts.index'));
    }

    public function test_settings_subscreens_go_back_to_settings(): void
    {
        foreach (['settings.users', 'settings.audit', 'settings.identity'] as $r) {
            $this->assertBackTo(route($r), route('settings'));
        }
    }
}
