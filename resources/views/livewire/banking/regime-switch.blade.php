<?php

use App\Domains\Banking\Services\OwnFinancialService;
use Livewire\Volt\Component;

/** Seletor persistente (por usuário) do regime usado no resultado: Caixa (padrão) ou Competência. */
new class extends Component
{
    public string $regime = 'cash';

    public function mount(): void
    {
        $this->regime = OwnFinancialService::currentRegime();
    }

    public function updatedRegime(): void
    {
        abort_unless(in_array($this->regime, [OwnFinancialService::CASH, OwnFinancialService::ACCRUAL], true), 422);
        auth()->user()?->forceFill(['financial_regime' => $this->regime])->save();
        app(\App\Domains\Dashboard\Services\DashboardSnapshotService::class)->invalidate();
        $this->redirect(request()->header('Referer') ?: url()->previous(), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-1">
    <label for="financial-regime" class="block text-fs-12 font-medium text-cryptex-text-tertiary uppercase tracking-[0.05em]">Regime do resultado</label>
    <select id="financial-regime" wire:model.live="regime" class="h-[40px] py-0 pl-3 pr-10 leading-5 rounded-sm bg-cryptex-bg-tertiary border border-cryptex-border-default text-fs-14 text-cryptex-text-primary">
        <option value="cash">Caixa (compra no cartão pelo pagamento da fatura)</option>
        <option value="accrual">Competência (compra no cartão pela data da compra)</option>
    </select>
</div>
