<?php

use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Services\PartnershipLedgerService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function delete(int $id, PartnershipLedgerService $ledger): void
    {
        $partnership = Partnership::find($id);
        if (! $partnership) {
            return;
        }

        $ledger->deletePartnership($partnership);
        session()->flash('status', 'Sociedade excluída.');
    }

    public function with(): array
    {
        return [
            'partnerships' => Partnership::query()
                ->withSum(['contributions as total_contributed' => fn ($query) => $query->where('status', 'done')], 'amount')
                ->withSum('expenses as total_expenses', 'proportional_amount')
                ->withSum('distributions as total_distributions', 'amount')
                ->orderBy('name')
                ->get(),
        ];
    }
}; ?>

<x-slot name="header">Sociedade · Lista</x-slot>

<div class="flex flex-col gap-space-5">
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

    <div class="flex flex-col gap-space-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-mono-900">Sociedades</h2>
            <p class="mt-1 text-sm text-mono-600">Acompanhe sua participação e o desempenho de cada sociedade.</p>
        </div>
        <x-fx.button href="{{ route('partnership.create') }}" variant="primary" size="sm">
            <span class="material-icons-outlined text-[18px]">add</span>
            Nova sociedade
        </x-fx.button>
    </div>

    @if ($partnerships->isEmpty())
        <x-jr.empty-state icon="corporate_fare" title="Nenhuma sociedade cadastrada" description="Cadastre sua primeira sociedade para acompanhar aportes, despesas e distribuições." />
    @else
        <div class="grid grid-cols-1 gap-space-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($partnerships as $p)
                @php
                    $nameParts = collect(preg_split('/\s+/', trim($p->name)))->filter();
                    $initials = mb_strtoupper(
                        mb_substr((string) $nameParts->first(), 0, 1).
                        ($nameParts->count() > 1 ? mb_substr((string) $nameParts->last(), 0, 1) : '')
                    );
                    $contributed = (float) ($p->total_contributed ?? 0);
                    $expenses = (float) ($p->total_expenses ?? 0);
                    $distributed = (float) ($p->total_distributions ?? 0);
                    $net = round($distributed - $contributed - $expenses, 2);
                    $invested = $contributed + $expenses;
                    $roi = $invested > 0 ? round(($net / $invested) * 100, 2) : 0;
                @endphp

                <article class="group flex min-h-[330px] flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-card transition-all duration-200 hover:-translate-y-0.5 hover:border-primary-100 hover:shadow-elevated">
                    <div class="relative overflow-hidden border-b border-mono-100 px-6 pb-5 pt-6">
                        <span class="material-icons-outlined pointer-events-none absolute -right-4 -top-5 text-[104px] text-primary-500/[0.04]">corporate_fare</span>

                        <div class="relative flex items-start justify-between gap-4">
                            <div class="flex min-w-0 items-center gap-4">
                                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary-400 to-primary-600 text-lg font-bold tracking-wide text-white shadow-[0_8px_20px_rgba(255,111,0,.22)]">
                                    {{ $initials }}
                                </div>
                                <div class="min-w-0">
                                    <a href="{{ route('partnership.show', $p) }}" class="block truncate text-base font-semibold text-mono-900 transition-colors group-hover:text-primary-500">
                                        {{ $p->name }}
                                    </a>
                                    <p class="mt-1 truncate font-mono text-xs text-mono-500">{{ $p->cnpj ?: 'CNPJ não informado' }}</p>
                                </div>
                            </div>

                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-pill px-2.5 py-1 text-xxs font-semibold {{ $p->status ? 'bg-system-up/10 text-system-up' : 'bg-mono-100 text-mono-600' }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $p->status ? 'bg-system-up' : 'bg-mono-400' }}"></span>
                                {{ $p->status ? 'Ativa' : 'Inativa' }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col px-6 py-5">
                        <div class="mb-5 flex items-center justify-between rounded-xl bg-mono-50 px-4 py-3">
                            <div>
                                <p class="text-xxs font-medium uppercase tracking-[0.05em] text-mono-500">Sua participação</p>
                                <p class="mt-1 font-mono text-lg font-bold text-mono-900">{{ number_format((float) $p->participation_percentage, 2, ',', '.') }}%</p>
                            </div>
                            <span class="material-icons-outlined text-[26px] text-primary-500">pie_chart_outline</span>
                        </div>

                        <div class="grid grid-cols-2 gap-5">
                            <div>
                                <p class="text-xxs font-medium uppercase tracking-[0.05em] text-mono-500">Aportado</p>
                                <p class="mt-1 whitespace-nowrap font-mono text-sm font-semibold text-mono-900">R$ {{ number_format($contributed, 2, ',', '.') }}</p>
                            </div>
                            <div>
                                <p class="text-xxs font-medium uppercase tracking-[0.05em] text-mono-500">Distribuído</p>
                                <p class="mt-1 whitespace-nowrap font-mono text-sm font-semibold text-system-up">R$ {{ number_format($distributed, 2, ',', '.') }}</p>
                            </div>
                        </div>

                        <div class="mt-5 flex items-end justify-between border-t border-mono-100 pt-4">
                            <div>
                                <p class="text-xxs font-medium uppercase tracking-[0.05em] text-mono-500">Resultado</p>
                                <p class="mt-1 whitespace-nowrap font-mono text-lg font-bold {{ $net >= 0 ? 'text-system-up' : 'text-system-down' }}">R$ {{ number_format($net, 2, ',', '.') }}</p>
                            </div>
                            <span class="rounded-pill px-2.5 py-1 font-mono text-xs font-semibold {{ $roi >= 0 ? 'bg-system-up/10 text-system-up' : 'bg-system-down/10 text-system-down' }}">
                                {{ $roi > 0 ? '+' : '' }}{{ number_format($roi, 2, ',', '.') }}%
                            </span>
                        </div>
                    </div>

                    <footer class="flex items-center justify-between border-t border-mono-100 bg-mono-50/70 px-4 py-3">
                        <a href="{{ route('partnership.show', $p) }}" class="fx-btn fx-btn--text fx-btn--sm">
                            Ver detalhes
                            <span class="material-icons-outlined text-[17px]">arrow_forward</span>
                        </a>
                        <div class="flex items-center gap-1">
                            <a href="{{ route('partnership.edit', $p) }}" class="fx-btn fx-btn--icon h-9 w-9" title="Editar sociedade" aria-label="Editar sociedade">
                                <span class="material-icons-outlined text-[18px]">edit</span>
                            </a>
                            <button type="button" class="fx-btn fx-btn--icon h-9 w-9 text-error" wire:click="delete({{ $p->id }})" wire:confirm="Excluir sociedade? Aportes, despesas, distribuições e lançamentos no caixa também serão excluídos." title="Excluir sociedade" aria-label="Excluir sociedade">
                                <span class="material-icons-outlined text-[18px]">delete</span>
                            </button>
                        </div>
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
</div>
