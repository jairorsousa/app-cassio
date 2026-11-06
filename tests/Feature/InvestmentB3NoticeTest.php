<?php

namespace Tests\Feature;

use App\Domains\Investments\Models\AssetIncomeEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvestmentB3NoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_notice_hidden_without_imported_data(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();
        Volt::test('investments.dividends.index')->assertDontSeeHtml('data-b3-notice');
        Volt::test('investments.positions')->assertDontSeeHtml('data-b3-notice');
    }

    public function test_dividends_page_points_to_imported_income(): void
    {
        AssetIncomeEvent::create(['ticker' => 'AAAA11', 'payment_date' => '2026-01-24', 'type' => 'fii_income', 'net_amount' => 7.90, 'source' => 'b3_midas', 'source_key' => 'b3inc:a:1']);
        $this->actingAs(User::factory()->create());
        Livewire::withoutLazyLoading();
        Volt::test('investments.dividends.index')->assertSeeHtml('data-b3-notice="income"')->assertSee('Posição B3');
    }
}
