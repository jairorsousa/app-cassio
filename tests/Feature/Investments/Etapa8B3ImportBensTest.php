<?php

namespace Tests\Feature\Investments;

use App\Domains\Assets\Models\Property;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\OwnFinancialService;
use App\Domains\Dashboard\Services\DashboardSnapshotBuilder;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\B3PositionSnapshot;
use App\Domains\Investments\Models\InvestmentImportBatch;
use App\Domains\Investments\Services\B3MidasImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Dados sintéticos no formato do export Midas (não são os dados reais). */
class Etapa8B3ImportBensTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const POS = "ticker,quantidade,preco_medio,cotacao_30out,valor_mercado,distancia_medio_pct,preco_medio_status,origem_b3,instituicoes,produto\n"
        ."AAAA3,100,10.00,12.00,1200.00,20.00,confirmado,custodia,CORRETORA X | CORRETORA Y,AAAA3 - EMPRESA A S.A.\n"
        ."BBBB3,50,,4.00,200.00,,desconhecido_confirmado_pelo_usuario,custodia,CORRETORA X,BBBB3 - EMPRESA B S.A.\n"
        ."CCCC3,30,5.00,6.00,180.00,20.00,confirmado,emprestimo_doador,CORRETORA X,CCCC3 - EMPRESA C S.A.\n"
        ."DDDD3,1000,,4.78,4780.00,,nao_informado,custodia+emprestimo_doador,CORRETORA X,DDDD3 - EMPRESA D SA\n"
        ."FFFF11,10,,100.00,1000.00,,nao_informado,custodia_fii,CORRETORA Z,FFFF11 - FUNDO F FII\n";

    private const INC = "data,ticker,tipo,quantidade,valor_bruto,valor_liquido,eh_reembolso_aluguel,classe,corretora,fonte_arquivo\n"
        ."2026-10-23,FFFF11,Rendimento,10,,8.10,nao,FII,Corretora Z,a.xlsx\n"
        ."2026-04-29,AAAA3,Dividendo,100,,13.04,nao,Ação,Corretora X,a.xlsx\n"
        ."2026-04-29,AAAA3,Dividendo,100,,13.04,nao,Ação,Corretora X,a.xlsx\n"
        ."2025-04-01,AAAA3,JCP,100,,2.00,nao,Ação,Corretora Y,b.xlsx\n"
        ."2025-03-10,CCCC3,Reembolso,30,,1.50,sim,Ação,Corretora X,b.xlsx\n";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        foreach ([['Ações', 'acoes'], ['FIIs', 'fiis'], ['BDRs', 'bdrs']] as [$n, $s]) {
            AssetClass::create(['name' => $n, 'slug' => $s]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function preview(?array $meta = null): array
    {
        return app(B3MidasImportService::class)->preview(self::POS, self::INC, '2026-10-30', $meta);
    }

    private function confirm(array $preview): array
    {
        $batch = InvestmentImportBatch::create(['source' => 'b3_midas', 'status' => 'preview', 'base_date' => '2026-10-30']);

        return app(B3MidasImportService::class)->confirm($preview, $batch, $this->user->id);
    }

    public function test_preview_validates_counts_and_writes_nothing(): void
    {
        $p = $this->preview(['posicao_rows' => 5, 'posicao_valor_total' => 7360.00, 'proventos_rows' => 5, 'proventos_sum_liquido' => 37.68, 'proventos_reembolsos' => 1, 'posicao_data_base' => '2026-10-30']);
        $this->assertTrue($p['ok'], implode("\n", $p['errors']));
        $s = $p['summary'];
        $this->assertSame(5, $s['positions']['count']);
        $this->assertEquals(7360.00, $s['positions']['market_value']);
        $this->assertSame(['BBBB3', 'DDDD3', 'FFFF11'], $s['positions']['unknown_average_price']);
        $this->assertSame(['CCCC3', 'DDDD3'], $s['positions']['lent_tickers']);
        $this->assertSame(5, $s['income']['count']);
        $this->assertSame(1, $s['income']['reimbursements']);
        $this->assertSame('2025-03-10', $s['income']['from']);
        $this->assertSame(0, $s['income']['gross_informed']);
        $this->assertNotEmpty(array_filter($p['warnings'], fn ($w) => str_contains($w, 'DDDD3') && str_contains($w, 'não separa')));
        $this->assertNotEmpty(array_filter($p['warnings'], fn ($w) => str_contains($w, 'linhas idênticas')));
        $this->assertSame(0, Asset::count() + B3PositionSnapshot::count() + AssetIncomeEvent::count());

        $bad = $this->preview(['proventos_sum_liquido' => 99]);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('meta.json', $bad['errors'][0]);
    }

    public function test_invalid_rows_block_confirmation(): void
    {
        $svc = app(B3MidasImportService::class);
        $p = $svc->preview("ticker,quantidade\nAAAA3,1\n", "data,ticker,tipo,quantidade,valor_bruto,valor_liquido,eh_reembolso_aluguel,classe,corretora,fonte_arquivo\n2026-02-30,AAAA3,Dividendo,1,,1,nao,Ação,X,a\n2026-01-01,AAAA3,Bonus,1,,1,nao,Ação,X,a\n2026-01-01,AAAA3,Dividendo,1,,1,sim,Ação,X,a\n", '2026-10-30');
        $this->assertFalse($p['ok']);
        $this->assertCount(4, $p['errors']);
        $this->assertStringContainsString('Colunas ausentes', $p['errors'][0]);
        $this->assertFalse($svc->preview(self::POS, null, null)['ok']); // posição exige data-base
        $this->expectException(\RuntimeException::class);
        $this->confirm($p);
    }

    public function test_confirm_imports_idempotently_without_financeiro_and_keeps_unknown_pm_null(): void
    {
        $account = BankAccount::create(['name' => 'BB', 'initial_balance' => 1000, 'status' => true]);
        $txBefore = Transaction::count();
        $created = $this->confirm($this->preview());
        $this->assertSame(['positions' => 5, 'income' => 5], $created);
        $this->assertSame(5, Asset::count());
        $this->assertSame(5, AssetIncomeEvent::count()); // duas linhas idênticas viram dois eventos
        $this->assertSame($txBefore, Transaction::count());
        $this->assertEquals(1000, $account->fresh()->balance());

        $b = B3PositionSnapshot::where('ticker', 'BBBB3')->first();
        $this->assertNull($b->average_price);
        $this->assertNull($b->cost());
        $this->assertSame('desconhecido_confirmado_pelo_usuario', $b->average_price_status);
        $c = B3PositionSnapshot::where('ticker', 'CCCC3')->first();
        $this->assertEquals(0, $c->quantity_custody);
        $this->assertEquals(30, $c->quantity_lent);
        $d = B3PositionSnapshot::where('ticker', 'DDDD3')->first();
        $this->assertNull($d->quantity_custody);
        $this->assertNull($d->quantity_lent);
        $this->assertEquals(1000, $d->quantity);
        $this->assertSame('FIIs', Asset::where('ticker', 'FFFF11')->first()->assetClass->name);
        $r = AssetIncomeEvent::where('type', 'lending_reimbursement')->first();
        $this->assertTrue($r->is_lending);
        $this->assertNull($r->lending_fee_rate);
        $this->assertNull($r->gross_amount);
        $this->assertNull(AssetIncomeEvent::whereNotNull('transaction_id')->first());

        // Reimportar: nada novo.
        $again = $this->preview();
        $this->assertSame(0, $again['summary']['income']['new']);
        $this->assertSame(5, $again['summary']['positions']['existing']);
        $this->assertSame(['positions' => 0, 'income' => 0], $this->confirm($again));
        $this->assertSame(5, AssetIncomeEvent::count());
        $this->assertSame(5, B3PositionSnapshot::count());
    }

    public function test_confirm_restores_soft_deleted_test_asset_without_reviving_operations(): void
    {
        $asset = Asset::create(['ticker' => 'AAAA3', 'name' => 'Teste', 'asset_class_id' => AssetClass::first()->id]);
        $op = AssetOperation::create(['asset_id' => $asset->id, 'date' => '2026-01-05', 'type' => 'buy', 'quantity' => 1, 'unit_price' => 1, 'fees' => 0, 'total' => 1]);
        $op->delete();
        $asset->delete();
        $this->confirm($this->preview());
        $this->assertFalse($asset->fresh()->trashed());
        $this->assertTrue($op->fresh()->trashed());
        $this->assertSame(1, Asset::withTrashed()->where('ticker', 'AAAA3')->count());
    }

    public function test_page_preview_requires_explicit_confirmation(): void
    {
        Storage::fake('local');
        $c = Volt::test('investments.b3.index')
            ->set('positionFile', UploadedFile::fake()->createWithContent('posicao.csv', self::POS))
            ->set('incomeFile', UploadedFile::fake()->createWithContent('proventos.csv', self::INC))
            ->set('baseDate', '2026-10-30')
            ->call('generatePreview')
            ->assertSee('Prévia da importação')->assertSee('Confirmar importação')->assertSee('PM ausente');
        $this->assertSame(0, AssetIncomeEvent::count());
        $this->assertSame('preview', InvestmentImportBatch::first()->status);

        $c->call('confirmImport')->assertSee('Importação confirmada');
        $this->assertSame(5, AssetIncomeEvent::count());
        $this->assertSame('confirmed', InvestmentImportBatch::first()->status);
        $this->assertSame($this->user->id, InvestmentImportBatch::first()->confirmed_by);
        $c->call('confirmImport')->assertSee('Prévia não encontrada');

        Volt::test('investments.b3.index')->assertSee('data-base')->assertSee('30/10/2026')->assertSee('não informado')->assertSee('não separado')->assertSee('Reembolso (aluguel)');
        $this->get('/investments/b3')->assertOk();
    }

    public function test_page_rejects_files_changed_after_preview(): void
    {
        Storage::fake('local');
        $c = Volt::test('investments.b3.index')->set('incomeFile', UploadedFile::fake()->createWithContent('proventos.csv', self::INC))->call('generatePreview');
        $batch = InvestmentImportBatch::first();
        Storage::disk('local')->put($batch->income_path, self::INC."2026-01-01,AAAA3,JCP,1,,9.99,nao,Ação,X,z\n");
        $c->call('confirmImport')->assertSee('mudaram depois da prévia');
        $this->assertSame(0, AssetIncomeEvent::count());
    }

    public function test_command_previews_by_default_and_needs_user_to_confirm(): void
    {
        $dir = sys_get_temp_dir().'/b3test'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/posicao.csv", self::POS);
        file_put_contents("$dir/proventos.csv", self::INC);
        file_put_contents("$dir/meta.json", json_encode(['posicao_data_base' => '2026-10-30', 'posicao_rows' => 5]));
        $this->artisan('investments:b3-import', ['dir' => $dir])->expectsOutputToContain('nada foi gravado')->assertSuccessful();
        $this->assertSame(0, AssetIncomeEvent::count());
        $this->artisan('investments:b3-import', ['dir' => $dir, '--confirm' => true])->assertFailed();
        $this->assertSame(0, AssetIncomeEvent::count());
        $this->artisan('investments:b3-import', ['dir' => $dir, '--confirm' => true, '--user' => $this->user->email])->assertSuccessful();
        $this->assertSame(5, AssetIncomeEvent::count());
    }

    public function test_command_fails_on_missing_or_unreadable_files(): void
    {
        $dir = sys_get_temp_dir().'/b3empty'.uniqid();
        mkdir($dir);
        $this->artisan('investments:b3-import', ['dir' => $dir])->expectsOutputToContain('Nenhum posicao.csv')->assertFailed();
        $this->artisan('investments:b3-import', ['dir' => $dir.'/nao-existe'])->assertFailed();
        file_put_contents("$dir/proventos.csv", self::INC);
        file_put_contents("$dir/meta.json", '{x');
        $this->artisan('investments:b3-import', ['dir' => $dir])->expectsOutputToContain('meta.json inválido')->assertFailed();
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            unlink("$dir/meta.json");
            chmod("$dir/proventos.csv", 0000);
            $this->artisan('investments:b3-import', ['dir' => $dir])->expectsOutputToContain('Sem permissão')->assertFailed();
            chmod("$dir/proventos.csv", 0600);
        }
        $this->assertSame(0, AssetIncomeEvent::count() + InvestmentImportBatch::count());
    }

    public function test_dashboard_uses_b3_snapshot_and_properties_without_double_counting(): void
    {
        $this->confirm($this->preview());
        Property::create(['kind' => 'imovel', 'description' => 'Apartamento', 'acquisition_value' => 300000, 'current_value' => 450000, 'status' => 'active']);
        Property::create(['kind' => 'veiculo', 'description' => 'Carro vendido', 'current_value' => 50000, 'status' => 'sold', 'sold_at' => '2026-01-01']);
        $s = app(DashboardSnapshotBuilder::class)->build();
        $this->assertEquals(7360.00, $s['patrimony']['portfolio_market_value']);
        $this->assertSame('b3:2026-10-30', $s['patrimony']['portfolio_source']);
        $this->assertEquals(450000, $s['patrimony']['properties']);
        $this->assertEquals(7360 + 450000, $s['patrimony']['total']);
        $this->assertEquals(8.10 + 13.04 + 13.04, $s['investments']['dividends_12m']);
        $this->assertEquals(0, app(OwnFinancialService::class)->totals(today()->subYears(3), today())['income']);
    }

    public function test_asset_buy_is_not_expense_and_only_realized_gain_is_income(): void
    {
        $account = BankAccount::create(['name' => 'XP', 'initial_balance' => 10000, 'status' => true]);
        $asset = Asset::create(['ticker' => 'ZZZZ3', 'name' => 'Z', 'asset_class_id' => AssetClass::first()->id]);
        AssetOperation::create(['asset_id' => $asset->id, 'date' => '2026-11-02', 'type' => 'buy', 'quantity' => 100, 'unit_price' => 10, 'fees' => 0, 'total' => 1000, 'bank_account_id' => $account->id]);
        AssetOperation::create(['asset_id' => $asset->id, 'date' => '2026-11-03', 'type' => 'sell', 'quantity' => 50, 'unit_price' => 14, 'fees' => 0, 'total' => 700, 'bank_account_id' => $account->id]);
        $t = app(OwnFinancialService::class)->totals(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')->endOfDay());
        $this->assertEquals(0, $t['expense']);
        $this->assertEquals(200, $t['income']); // 700 - custo médio 500
        $this->assertEquals(9700, $account->fresh()->balance()); // caixa continua correto
    }

    public function test_properties_crud_with_private_attachment(): void
    {
        Storage::fake('local');
        Volt::test('bens.index')->call('create')
            ->set('kind', 'veiculo')->set('description', 'Carro')->set('identifier', 'ABC1D23')->set('owner', 'luciane')
            ->set('acquired_at', '2024-05-01')->set('acquisition_value', '90000')->set('current_value', '70000')->set('current_value_date', '2026-11-01')
            ->set('attachment', UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'))
            ->call('save')->assertHasNoErrors()->assertSee('Bem cadastrado')->assertSee('Carro')->assertSee('Luciane');
        $p = Property::first();
        $this->assertTrue($p->hasAttachment());
        $this->assertStringStartsWith('bens/'.$p->id.'/', $p->attachment_path);
        $this->assertSame(0, Transaction::count());
        $this->get(route('bens.attachment', $p))->assertOk();
        Volt::test('bens.index')->call('edit', $p->id)->set('status', 'sold')->set('sold_at', '')->call('save')->assertHasErrors(['sold_at']);
        Volt::test('bens.index')->call('create')->set('description', '')->set('attachment', UploadedFile::fake()->create('x.exe', 1))->call('save')->assertHasErrors(['description', 'attachment']);
        auth()->logout();
        $this->get(route('bens.attachment', $p))->assertRedirect('/login');
        $this->get('/bens')->assertRedirect('/login');
    }
}
