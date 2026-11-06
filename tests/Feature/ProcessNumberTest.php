<?php

namespace Tests\Feature;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Writs\Models\Writ;
use App\Models\User;
use App\Support\ProcessNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProcessNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_formata_somente_20_digitos(): void
    {
        $this->assertSame('0136933-55.2026.4.01.9198', ProcessNumber::format('01369335520264019198'));
        $this->assertSame('0136933-55.2026.4.01.9198', ProcessNumber::format(' 0136933-55.2026.4.01.9198 '));
        $this->assertSame('0136933-55.2026.4.01.9198', ProcessNumber::format('0136933 55 2026 4 01 9198'));
        $this->assertSame('1369335520264019198', ProcessNumber::format('1369335520264019198'), '19 dígitos: não completa com zero');
        $this->assertSame('123-45', ProcessNumber::format('123-45'));
        $this->assertSame('Processo 01369335520264019198', ProcessNumber::format('Processo 01369335520264019198'));
        $this->assertNull(ProcessNumber::format(null));
    }

    public function test_modelos_gravam_formato_cnj(): void
    {
        $this->actingAs(User::factory()->create());
        $writ = Writ::create(['type' => 'rpv', 'stage' => 'paid', 'assignor_name' => 'A', 'process_number' => '01369335520264019198', 'face_value' => 100, 'paid_amount' => 50]);
        $this->assertSame('0136933-55.2026.4.01.9198', DB::table('writs')->where('id', $writ->id)->value('process_number'));
        $this->assertSame('50.00', (string) $writ->fresh()->paid_amount);

        $bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 0, 'status' => true]);
        $t = Transaction::create(['type' => 'income', 'amount' => '10', 'date' => now()->toDateString(), 'description' => 'X', 'status' => 'settled', 'bank_account_id' => $bank->id, 'link_process' => '01369335520264019198']);
        $this->assertSame('0136933-55.2026.4.01.9198', $t->fresh()->link_process);
        $this->assertSame('10.00', $t->fresh()->amount);
        $t->update(['link_process' => 'ref livre 12']);
        $this->assertSame('ref livre 12', $t->fresh()->link_process);
    }

    public function test_comando_previa_e_aplicacao(): void
    {
        $this->actingAs(User::factory()->create());
        $bank = BankAccount::create(['name' => 'BB', 'initial_balance' => 0, 'status' => true]);
        $id = DB::table('transactions')->insertGetId(['type' => 'income', 'amount' => '10', 'date' => now()->toDateString(), 'description' => 'X', 'status' => 'settled', 'bank_account_id' => $bank->id, 'link_process' => '01369335520264019198', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('transactions')->insertGetId(['type' => 'income', 'amount' => '10', 'date' => now()->toDateString(), 'description' => 'Y', 'status' => 'settled', 'bank_account_id' => $bank->id, 'link_process' => '12345', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('process-numbers:normalize')->assertSuccessful();
        $this->assertSame('01369335520264019198', DB::table('transactions')->where('id', $id)->value('link_process'));
        $this->artisan('process-numbers:normalize --confirm')->assertSuccessful();
        $this->assertSame('0136933-55.2026.4.01.9198', DB::table('transactions')->where('id', $id)->value('link_process'));
        $this->assertSame('12345', DB::table('transactions')->where('id', $other)->value('link_process'));
    }

    public function test_campos_de_processo_tem_mascara(): void
    {
        $this->actingAs(User::factory()->create());
        Volt::test('banking.transactions.index')->call('create', 'income')->call('toggleOptionalLinks')
            ->assertSeeHtml('x-process-number');
    }
}
