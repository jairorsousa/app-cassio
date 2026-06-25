<?php

use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Services\BrokerBalanceCalculator;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Broker $broker;

    public function mount(Broker $broker): void
    {
        $this->broker = $broker->load('advances', 'commissions.caseType', 'commissionRules.caseType');
    }

    public function with(BrokerBalanceCalculator $calc): array
    {
        $advanceBalance = $calc->forBroker($this->broker);
        $commissionSummary = $calc->commissionsForBroker($this->broker);
        $recentAdvances = $this->broker->advances()->with('bankAccount')->orderByDesc('date')->limit(5)->get();
        $recentCommissions = $this->broker->commissions()->with('caseType', 'bankAccount')->orderByDesc('reference_date')->limit(5)->get();

        return compact('advanceBalance', 'commissionSummary', 'recentAdvances', 'recentCommissions');
    }
}; ?>

<x-slot name="header">{{ $broker->name }}</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    <a href="{{ route('brokers.index') }}" class="inline-flex w-fit items-center gap-xxs text-sm font-medium text-mono-600 transition-colors hover:text-primary-500">
        <span class="material-icons-outlined text-base">arrow_back</span>
        Voltar
    </a>

    <x-fx.card class="p-md md:p-lg">
        <div class="flex flex-col gap-md lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-sm">
                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-500 md:h-20 md:w-20">
                    <span class="material-icons-outlined text-4xl">person</span>
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-xs">
                        <h2 class="truncate text-2xl font-bold text-mono-900 md:text-[32px]">{{ $broker->name }}</h2>
                        <span class="inline-flex items-center gap-xxs rounded-pill px-3 py-1 text-xs font-semibold {{ $broker->status ? 'bg-up-bg text-up' : 'bg-mono-100 text-mono-600' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $broker->status ? 'bg-up' : 'bg-mono-400' }}"></span>
                            {{ $broker->status ? 'Ativo' : 'Inativo' }}
                        </span>
                    </div>
                    <p class="mt-xxs text-sm text-mono-600">Corretor</p>
                </div>
            </div>

            <div class="flex flex-wrap gap-xs">
                <a href="{{ route('brokers.edit', $broker) }}" class="fx-btn fx-btn--standard fx-btn--sm">
                    <span class="material-icons-outlined text-base">edit</span>
                    Editar
                </a>
                <a href="{{ route('brokers.index') }}" class="fx-btn fx-btn--text fx-btn--sm border border-primary-500 text-primary-500 hover:bg-primary-100">
                    <span class="material-icons-outlined text-base">arrow_back</span>
                    Voltar
                </a>
            </div>
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-primary-100 bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-2xl">account_balance_wallet</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Adiantamentos</div>
                    <div class="mt-xxs truncate text-xl font-bold text-mono-900">R$ {{ number_format($advanceBalance['total_advanced'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-up-bg bg-up-bg text-up">
                    <span class="material-icons-outlined text-2xl">sync</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Compensado</div>
                    <div class="mt-xxs truncate text-xl font-bold text-up">R$ {{ number_format($advanceBalance['total_settled'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-down-bg bg-down-bg text-down">
                    <span class="material-icons-outlined text-2xl">balance</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Saldo a compensar</div>
                    <div class="mt-xxs truncate text-xl font-bold {{ $advanceBalance['balance'] > 0 ? 'text-down' : 'text-up' }}">
                        R$ {{ number_format($advanceBalance['balance'], 2, ',', '.') }}
                    </div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-primary-100 bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-2xl">percent</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Comissões pendentes</div>
                    <div class="mt-xxs truncate text-xl font-bold text-mono-900">R$ {{ number_format($commissionSummary['total_pending'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        <x-fx.card>
            <div class="mb-sm flex items-center gap-xs">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-xl">badge</span>
                </div>
                <h3 class="text-base font-semibold text-mono-900">Dados Cadastrais</h3>
            </div>
            <div class="grid grid-cols-1 gap-xs border-t border-mono-100 pt-sm text-sm sm:grid-cols-2">
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">assignment_ind</span>
                    <span class="min-w-24 text-mono-600">Documento</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->document ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">credit_card</span>
                    <span class="min-w-24 text-mono-600">RG</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->rg ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">calendar_month</span>
                    <span class="min-w-24 text-mono-600">Nascimento</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->birth_date?->format('d/m/Y') ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">verified</span>
                    <span class="min-w-24 text-mono-600">Status</span>
                    <span class="inline-flex items-center gap-xxs rounded-pill px-2.5 py-1 text-xs font-semibold {{ $broker->status ? 'bg-up-bg text-up' : 'bg-mono-100 text-mono-600' }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $broker->status ? 'bg-up' : 'bg-mono-400' }}"></span>
                        {{ $broker->status ? 'Ativo' : 'Inativo' }}
                    </span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">call</span>
                    <span class="min-w-24 text-mono-600">Telefone</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->phone ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">mail</span>
                    <span class="min-w-24 text-mono-600">E-mail</span>
                    <span class="min-w-0 truncate font-medium text-mono-900">{{ $broker->email ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs sm:col-span-2">
                    <span class="material-icons-outlined text-lg text-mono-400">location_on</span>
                    <span class="min-w-24 text-mono-600">Endereço</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->address ?: '—' }}</span>
                </div>
            </div>

            <div class="my-md border-t border-dashed border-mono-200"></div>

            <div class="mb-sm flex items-center gap-xs">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-xl">account_balance</span>
                </div>
                <h3 class="text-base font-semibold text-mono-900">Dados Bancários</h3>
            </div>
            <div class="grid grid-cols-1 gap-xs text-sm sm:grid-cols-2">
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">account_balance</span>
                    <span class="min-w-24 text-mono-600">Banco</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->bank_name ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">business</span>
                    <span class="min-w-24 text-mono-600">Agência</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->bank_agency ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">payment</span>
                    <span class="min-w-24 text-mono-600">Conta</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->bank_account ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">wallet</span>
                    <span class="min-w-24 text-mono-600">Tipo</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->bank_account_type ?: '—' }}</span>
                </div>
                <div class="flex items-center gap-xs sm:col-span-2">
                    <span class="material-icons-outlined text-lg text-mono-400">pix</span>
                    <span class="min-w-24 text-mono-600">PIX</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->pix_key ?: '—' }}</span>
                </div>
            </div>

            @if ($broker->notes)
                <div class="my-md border-t border-dashed border-mono-200"></div>
                <div class="mb-xs flex items-center gap-xs">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-500">
                        <span class="material-icons-outlined text-xl">sticky_note_2</span>
                    </div>
                    <h3 class="text-base font-semibold text-mono-900">Observações</h3>
                </div>
                <p class="text-sm leading-6 text-mono-900">{{ $broker->notes }}</p>
            @endif
        </x-fx.card>

        <div class="flex flex-col gap-md">
            <x-fx.card>
                <div class="mb-sm flex items-center gap-xs">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-500">
                        <span class="material-icons-outlined text-xl">bolt</span>
                    </div>
                    <h3 class="text-base font-semibold text-mono-900">Ações</h3>
                </div>
                <div class="flex flex-col gap-xs">
                    <a href="{{ route('brokers.advances.create', $broker) }}" class="fx-btn fx-btn--primary fx-btn--sm w-full gap-xs text-center">
                        <span class="material-icons-outlined text-base">add</span>
                        Novo adiantamento
                    </a>
                    <a href="{{ route('brokers.commissions.create', $broker) }}" class="fx-btn fx-btn--standard fx-btn--sm w-full gap-xs border-primary-500 text-primary-500 hover:bg-primary-100">
                        <span class="material-icons-outlined text-base">add</span>
                        Registrar comissão
                    </a>
                    <a href="{{ route('brokers.advances.index', $broker) }}" class="fx-btn fx-btn--standard fx-btn--sm w-full justify-between">
                        <span>Ver adiantamentos</span>
                        <span class="material-icons-outlined text-base">chevron_right</span>
                    </a>
                    <a href="{{ route('brokers.commissions.index', $broker) }}" class="fx-btn fx-btn--standard fx-btn--sm w-full justify-between">
                        <span>Ver comissões</span>
                        <span class="material-icons-outlined text-base">chevron_right</span>
                    </a>
                </div>
            </x-fx.card>

            <x-fx.card>
                <div class="mb-sm flex items-center gap-xs">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-500">
                        <span class="material-icons-outlined text-xl">verified_user</span>
                    </div>
                    <h3 class="text-base font-semibold text-mono-900">Regras de Comissão</h3>
                </div>
                @if ($broker->commissionRules->isEmpty())
                    <div class="flex min-h-32 flex-col items-center justify-center rounded-lg border border-dashed border-mono-200 bg-mono-50 px-sm py-lg text-center">
                        <span class="material-icons-outlined mb-xs text-5xl text-mono-400">description</span>
                        <div class="text-sm font-medium text-mono-900">Nenhuma regra cadastrada.</div>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="fx-table w-full text-sm">
                            <thead><tr><th class="text-left">Tipo de caso</th><th class="text-right">%</th><th class="text-right">Validade</th></tr></thead>
                            <tbody>
                            @foreach ($broker->commissionRules as $rule)
                                <tr>
                                    <td>{{ $rule->caseType->name }}</td>
                                    <td class="text-right">{{ number_format($rule->percentage, 1, ',', '.') }}%</td>
                                    <td class="text-right text-xxs text-mono-600">
                                        {{ $rule->valid_from->format('d/m/Y') }}
                                        @if ($rule->valid_to) — {{ $rule->valid_to->format('d/m/Y') }} @else — atual @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fx.card>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        <x-fx.card>
            <div class="mb-sm flex items-center justify-between gap-sm border-b border-mono-100 pb-sm">
                <div class="flex items-center gap-xs">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-500">
                        <span class="material-icons-outlined text-xl">history</span>
                    </div>
                    <h3 class="text-base font-semibold text-mono-900">Últimos Adiantamentos</h3>
                </div>
                <a href="{{ route('brokers.advances.index', $broker) }}" class="inline-flex items-center gap-xxs text-xs font-semibold text-primary-500 hover:text-primary-600">
                    Ver todos
                    <span class="material-icons-outlined text-base">arrow_forward</span>
                </a>
            </div>
            @if ($recentAdvances->isEmpty())
                <div class="flex min-h-24 items-center justify-center gap-sm rounded-lg bg-mono-50 px-sm py-md">
                    <span class="material-icons-outlined text-4xl text-mono-400">inventory_2</span>
                    <div>
                        <div class="text-sm font-semibold text-mono-900">Nenhum adiantamento.</div>
                        <div class="text-xs text-mono-600">Os adiantamentos realizados aparecerão aqui.</div>
                    </div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="fx-table w-full text-sm">
                        <thead><tr><th class="text-left">Data</th><th class="text-left">Forma</th><th class="text-right">Valor</th><th class="text-right">Saldo restante</th></tr></thead>
                        <tbody>
                            @foreach ($recentAdvances as $adv)
                                <tr>
                                    <td>{{ $adv->date->format('d/m/Y') }}</td>
                                    <td>{{ $adv->payment_method ?: '—' }}</td>
                                    <td class="text-right">R$ {{ number_format($adv->amount, 2, ',', '.') }}</td>
                                    <td class="text-right {{ $adv->remainingBalance() > 0 ? 'text-down' : 'text-up' }}">
                                        R$ {{ number_format($adv->remainingBalance(), 2, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-fx.card>

        <x-fx.card>
            <div class="mb-sm flex items-center justify-between gap-sm border-b border-mono-100 pb-sm">
                <div class="flex items-center gap-xs">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-100 text-primary-500">
                        <span class="material-icons-outlined text-xl">percent</span>
                    </div>
                    <h3 class="text-base font-semibold text-mono-900">Últimas Comissões</h3>
                </div>
                <a href="{{ route('brokers.commissions.index', $broker) }}" class="inline-flex items-center gap-xxs text-xs font-semibold text-primary-500 hover:text-primary-600">
                    Ver todas
                    <span class="material-icons-outlined text-base">arrow_forward</span>
                </a>
            </div>
            @if ($recentCommissions->isEmpty())
                <div class="flex min-h-24 items-center justify-center gap-sm rounded-lg bg-mono-50 px-sm py-md">
                    <span class="material-icons-outlined text-4xl text-mono-400">inventory_2</span>
                    <div>
                        <div class="text-sm font-semibold text-mono-900">Nenhuma comissão.</div>
                        <div class="text-xs text-mono-600">As comissões registradas aparecerão aqui.</div>
                    </div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="fx-table w-full text-sm">
                        <thead><tr><th class="text-left">Data ref.</th><th class="text-left">Tipo de caso</th><th class="text-right">Comissão</th><th class="text-center">Status</th></tr></thead>
                        <tbody>
                            @foreach ($recentCommissions as $com)
                                <tr>
                                    <td>{{ $com->reference_date->format('d/m/Y') }}</td>
                                    <td>{{ $com->caseType->name }}</td>
                                    <td class="text-right font-semibold">R$ {{ number_format($com->commission_amount, 2, ',', '.') }}</td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center rounded-pill px-2.5 py-1 text-xs font-semibold {{ $com->status === 'paid' ? 'bg-up-bg text-up' : ($com->status === 'partially_paid' ? 'bg-mono-100 text-mono-600' : 'bg-down-bg text-down') }}">
                                            {{ ['pending' => 'Pendente', 'paid' => 'Pago', 'partially_paid' => 'Parcial'][$com->status] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-fx.card>
    </div>
</div>
