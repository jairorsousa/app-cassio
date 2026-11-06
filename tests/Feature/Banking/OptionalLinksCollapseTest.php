<?php

namespace Tests\Feature\Banking;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OptionalLinksCollapseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_nova_receita_comeca_recolhida_e_abre_no_clique(): void
    {
        $c = Volt::test('banking.transactions.index')->call('create', 'income')
            ->assertSet('showOptionalLinks', false)
            ->assertSee('Adicionar vínculos ou comprovante (opcional)')
            ->assertDontSee('Buscar requisitório, corretor ou pessoa');
        $c->call('toggleOptionalLinks')->assertSee('Buscar requisitório, corretor ou pessoa')->assertSee('Ocultar vínculos e comprovante');
        $c->set('formLinkProcess', '999')->call('toggleOptionalLinks')->assertSet('formLinkProcess', '999')->assertSee('1 preenchido(s)');
        $c->call('cancel')->call('create', 'expense')->assertSet('showOptionalLinks', false)->assertSet('formLinkProcess', '');
    }

    public function test_erro_de_validacao_no_vinculo_mantem_secao_aberta(): void
    {
        $bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 0, 'status' => true]);
        $cat = Category::create(['name' => 'Receita X', 'type' => 'income', 'status' => true]);
        Volt::test('banking.transactions.index')->call('create', 'income')
            ->set('formAmount', '10')->set('formDescription', 'Teste')->set('formBankAccountId', $bank->id)->set('formCategoryId', $cat->id)
            ->set('formLinkWritId', 999999)->call('saveTransaction')
            ->assertHasErrors('formLinkWritId')->assertSee('Buscar requisitório, corretor ou pessoa');
    }

    public function test_edicao_abre_automaticamente_so_quando_ha_vinculo(): void
    {
        $bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 0, 'status' => true]);
        $cat = Category::create(['name' => 'Receita X', 'type' => 'income', 'status' => true]);
        $writ = Writ::create(['type' => 'rpv', 'stage' => 'paid', 'assignor_name' => 'Ana', 'process_number' => '1', 'face_value' => 100, 'paid_amount' => 50]);
        $base = ['type' => 'income', 'amount' => '10', 'date' => now()->toDateString(), 'status' => 'settled', 'bank_account_id' => $bank->id, 'category_id' => $cat->id];
        $plain = Transaction::create($base + ['description' => 'Sem vínculo']);
        $linked = Transaction::create($base + ['description' => 'Com vínculo', 'link_writ_id' => $writ->id]);

        Volt::test('banking.transactions.index')->call('edit', $plain->id)->assertSet('showOptionalLinks', false);
        Volt::test('banking.transactions.index')->call('edit', $linked->id)->assertSet('showOptionalLinks', true)->assertSee('Buscar requisitório, corretor ou pessoa');
    }
}
