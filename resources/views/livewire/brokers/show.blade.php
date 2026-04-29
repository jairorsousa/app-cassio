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

    {{-- Cards de resumo --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-md">
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Adiantamentos</div>
            <div class="text-xl font-bold">R$ {{ number_format($advanceBalance['total_advanced'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Compensado</div>
            <div class="text-xl font-bold text-system-up">R$ {{ number_format($advanceBalance['total_settled'], 2, ',', '.') }}</div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Saldo a compensar</div>
            <div class="text-xl font-bold {{ $advanceBalance['balance'] > 0 ? 'text-system-down' : '' }}">
                R$ {{ number_format($advanceBalance['balance'], 2, ',', '.') }}
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="text-xxs text-mono-600 uppercase">Comissões pendentes</div>
            <div class="text-xl font-bold">R$ {{ number_format($commissionSummary['total_pending'], 2, ',', '.') }}</div>
        </x-fx.card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        {{-- Dados cadastrais --}}
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Dados Cadastrais</h3>
            <div class="grid grid-cols-2 gap-xs text-sm">
                <div><span class="text-mono-600">Documento:</span> {{ $broker->document ?: '—' }}</div>
                <div><span class="text-mono-600">RG:</span> {{ $broker->rg ?: '—' }}</div>
                <div><span class="text-mono-600">Nascimento:</span> {{ $broker->birth_date?->format('d/m/Y') ?: '—' }}</div>
                <div><span class="text-mono-600">Status:</span> <x-fx.badge :variant="$broker->status ? 'up' : 'neutral'">{{ $broker->status ? 'Ativo' : 'Inativo' }}</x-fx.badge></div>
                <div><span class="text-mono-600">Telefone:</span> {{ $broker->phone ?: '—' }}</div>
                <div><span class="text-mono-600">E-mail:</span> {{ $broker->email ?: '—' }}</div>
                <div class="col-span-2"><span class="text-mono-600">Endereço:</span> {{ $broker->address ?: '—' }}</div>
            </div>

            <h3 class="text-md font-semibold mt-md mb-sm">Dados Bancários</h3>
            <div class="grid grid-cols-2 gap-xs text-sm">
                <div><span class="text-mono-600">Banco:</span> {{ $broker->bank_name ?: '—' }}</div>
                <div><span class="text-mono-600">Agência:</span> {{ $broker->bank_agency ?: '—' }}</div>
                <div><span class="text-mono-600">Conta:</span> {{ $broker->bank_account ?: '—' }}</div>
                <div><span class="text-mono-600">Tipo:</span> {{ $broker->bank_account_type ?: '—' }}</div>
                <div class="col-span-2"><span class="text-mono-600">PIX:</span> {{ $broker->pix_key ?: '—' }}</div>
            </div>

            @if ($broker->notes)
                <h3 class="text-md font-semibold mt-md mb-sm">Observações</h3>
                <p class="text-sm text-mono-700">{{ $broker->notes }}</p>
            @endif

            <div class="mt-md flex gap-xs">
                <a href="{{ route('brokers.edit', $broker) }}" class="fx-btn fx-btn--standard fx-btn--sm">Editar</a>
                <a href="{{ route('brokers.index') }}" class="fx-btn fx-btn--text fx-btn--sm">← Voltar</a>
            </div>
        </x-fx.card>

        {{-- Ações rápidas --}}
        <div class="flex flex-col gap-md">
            <x-fx.card>
                <h3 class="text-md font-semibold mb-sm">Ações</h3>
                <div class="flex flex-col gap-xs">
                    <a href="{{ route('brokers.advances.create', $broker) }}" class="fx-btn fx-btn--primary fx-btn--sm w-full text-center">+ Novo adiantamento</a>
                    <a href="{{ route('brokers.commissions.create', $broker) }}" class="fx-btn fx-btn--standard fx-btn--sm w-full text-center">+ Registrar comissão</a>
                    <a href="{{ route('brokers.advances.index', $broker) }}" class="fx-btn fx-btn--text fx-btn--sm w-full text-center">Ver adiantamentos</a>
                    <a href="{{ route('brokers.commissions.index', $broker) }}" class="fx-btn fx-btn--text fx-btn--sm w-full text-center">Ver comissões</a>
                </div>
            </x-fx.card>

            {{-- Regras de comissão vigentes --}}
            <x-fx.card>
                <h3 class="text-md font-semibold mb-sm">Regras de Comissão</h3>
                @if ($broker->commissionRules->isEmpty())
                    <div class="text-sm text-mono-600">Nenhuma regra cadastrada.</div>
                @else
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
                @endif
            </x-fx.card>
        </div>
    </div>

    {{-- Últimos adiantamentos --}}
    <x-fx.card>
        <div class="flex justify-between items-center mb-sm">
            <h3 class="text-md font-semibold">Últimos Adiantamentos</h3>
            <a href="{{ route('brokers.advances.index', $broker) }}" class="text-xxs text-primary-500 hover:underline">Ver todos →</a>
        </div>
        @if ($recentAdvances->isEmpty())
            <div class="text-sm text-mono-600">Nenhum adiantamento.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Data</th><th class="text-left">Forma</th><th class="text-right">Valor</th><th class="text-right">Saldo restante</th></tr></thead>
                <tbody>
                    @foreach ($recentAdvances as $adv)
                        <tr>
                            <td>{{ $adv->date->format('d/m/Y') }}</td>
                            <td>{{ $adv->payment_method ?: '—' }}</td>
                            <td class="text-right">R$ {{ number_format($adv->amount, 2, ',', '.') }}</td>
                            <td class="text-right {{ $adv->remainingBalance() > 0 ? 'text-system-down' : 'text-system-up' }}">
                                R$ {{ number_format($adv->remainingBalance(), 2, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    {{-- Últimas comissões --}}
    <x-fx.card>
        <div class="flex justify-between items-center mb-sm">
            <h3 class="text-md font-semibold">Últimas Comissões</h3>
            <a href="{{ route('brokers.commissions.index', $broker) }}" class="text-xxs text-primary-500 hover:underline">Ver todas →</a>
        </div>
        @if ($recentCommissions->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma comissão.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Data ref.</th><th class="text-left">Tipo de caso</th><th class="text-right">Base</th><th class="text-right">%</th><th class="text-right">Comissão</th><th class="text-center">Status</th></tr></thead>
                <tbody>
                    @foreach ($recentCommissions as $com)
                        <tr>
                            <td>{{ $com->reference_date->format('d/m/Y') }}</td>
                            <td>{{ $com->caseType->name }}</td>
                            <td class="text-right">R$ {{ number_format($com->base_amount, 2, ',', '.') }}</td>
                            <td class="text-right">{{ number_format($com->percentage_applied, 1, ',', '.') }}%</td>
                            <td class="text-right font-semibold">R$ {{ number_format($com->commission_amount, 2, ',', '.') }}</td>
                            <td class="text-center">
                                <x-fx.badge :variant="$com->status === 'paid' ? 'up' : ($com->status === 'partially_paid' ? 'neutral' : 'down')">
                                    {{ ['pending' => 'Pendente', 'paid' => 'Pago', 'partially_paid' => 'Parcial'][$com->status] }}
                                </x-fx.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>
</div>
