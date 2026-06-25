<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerBalanceCalculator;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerProfileService;
use App\Domains\Brokers\Services\BrokerStatementService;
use App\Domains\Contacts\Models\Contact;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Contact $broker;
    public ?Broker $financialBroker = null;

    #[Url]
    public string $period = 'all';
    #[Url]
    public string $start_date = '';
    #[Url]
    public string $end_date = '';
    #[Url]
    public string $records_tab = 'statement';

    public bool $showLaunchModal = false;
    public string $launch_type = 'advance';
    public string $launch_date = '';
    public string $launch_amount = '';
    public string $launch_base_amount = '';
    public ?int $launch_case_type_id = null;
    public ?int $launch_commission_id = null;
    public string $launch_payment_method = 'PIX';
    public ?int $launch_bank_account_id = null;
    public string $launch_notes = '';

    public function mount(Contact $broker): void
    {
        abort_unless($broker->type === 'corretor', 404);

        $this->broker = $broker;
        $this->financialBroker = app(BrokerProfileService::class)->forContact($broker);

        if ($this->financialBroker) {
            $this->financialBroker->load('advances', 'commissions.caseType');
        }

        $this->launch_date = now()->format('Y-m-d');
    }

    public function updatedPeriod(): void
    {
        if ($this->period === 'month') {
            $this->start_date = now()->startOfMonth()->format('Y-m-d');
            $this->end_date = now()->endOfMonth()->format('Y-m-d');
        }

        if ($this->period === 'year') {
            $this->start_date = now()->startOfYear()->format('Y-m-d');
            $this->end_date = now()->endOfYear()->format('Y-m-d');
        }

        if ($this->period === 'all') {
            $this->start_date = '';
            $this->end_date = '';
        }
    }

    public function openLaunchModal(string $type = 'advance'): void
    {
        $this->resetLaunchForm();
        $this->launch_type = $type;
        $this->showLaunchModal = true;
    }

    public function cancelLaunchModal(): void
    {
        $this->resetLaunchForm();
    }

    public function updatedLaunchType(): void
    {
        $this->launch_commission_id = null;
        $this->resetErrorBag();
    }

    public function saveLaunch(BrokerCommissionService $commissions): void
    {
        $this->normalizeLaunchAmounts();

        $rules = [
            'launch_type' => 'required|in:advance,commission,payment',
            'launch_date' => 'required|date',
            'launch_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'launch_notes' => 'nullable|string',
        ];

        if ($this->launch_type === 'advance') {
            $rules += [
                'launch_amount' => 'required|numeric|min:0.01',
                'launch_payment_method' => 'nullable|string|max:50',
            ];
        }

        if ($this->launch_type === 'commission') {
            $rules += [
                'launch_case_type_id' => 'required|exists:case_types,id',
                'launch_amount' => 'required|numeric|min:0.01',
            ];
        }

        if ($this->launch_type === 'payment') {
            $rules += [
                'launch_commission_id' => 'required|exists:broker_commissions,id',
                'launch_amount' => 'required|numeric|min:0.01',
            ];
        }

        $data = $this->validate($rules);

        try {
            if ($data['launch_type'] === 'advance') {
                $advance = BrokerAdvance::create([
                    'broker_id' => $this->financialBroker->id,
                    'date' => $data['launch_date'],
                    'amount' => $data['launch_amount'],
                    'payment_method' => $data['launch_payment_method'] ?: null,
                    'bank_account_id' => $data['launch_bank_account_id'] ?? null,
                    'notes' => $data['launch_notes'] ?: null,
                ]);

                BrokerAdvancePaid::dispatch($advance->load('broker'));
                session()->flash('status', 'Adiantamento registrado.');
            }

            if ($data['launch_type'] === 'commission') {
                $commission = $commissions->registerFixedAmount([
                    'broker_id' => $this->financialBroker->id,
                    'case_type_id' => $data['launch_case_type_id'],
                    'commission_amount' => $data['launch_amount'],
                    'reference_date' => $data['launch_date'],
                    'bank_account_id' => $data['launch_bank_account_id'] ?? null,
                    'notes' => $data['launch_notes'] ?: null,
                ]);

                $settled = $commissions->settleWithAdvances($commission);
                $message = 'Comissão registrada.';

                if ($settled > 0) {
                    $message .= ' Compensado R$ '.number_format($settled, 2, ',', '.').' em adiantamentos.';
                }

                session()->flash('status', $message);
            }

            if ($data['launch_type'] === 'payment') {
                $commission = BrokerCommission::where('broker_id', $this->financialBroker->id)
                    ->findOrFail($data['launch_commission_id']);

                $commissions->payAmount(
                    $commission,
                    (float) $data['launch_amount'],
                    $data['launch_date'],
                    $data['launch_bank_account_id'] ?? null,
                    $data['launch_notes'] ?: null,
                );

                session()->flash('status', 'Repasse registrado.');
            }

            $this->financialBroker->refresh();
            $this->resetLaunchForm();
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function with(BrokerBalanceCalculator $calc, BrokerStatementService $statementService): array
    {
        $advanceBalance = [
            'total_advanced' => 0.0,
            'total_settled' => 0.0,
            'balance' => 0.0,
        ];
        $commissionSummary = [
            'total_commissions' => 0.0,
            'total_pending' => 0.0,
            'total_paid' => 0.0,
        ];
        $allAdvances = collect();
        $allCommissions = collect();
        $allPayments = collect();

        $periodStart = $this->start_date ?: null;
        $periodEnd = $this->end_date ?: null;
        $statementSummary = [
            'advanced_period' => 0.0,
            'advance_pending' => 0.0,
            'commissions_period' => 0.0,
            'settled_period' => 0.0,
            'repassed_period' => 0.0,
            'commission_pending' => 0.0,
            'cash_out_period' => 0.0,
        ];
        $statementEntries = collect();
        $openCommissions = collect();

        if ($this->financialBroker) {
            $advanceBalance = $calc->forBroker($this->financialBroker);
            $commissionSummary = $calc->commissionsForBroker($this->financialBroker);
            $statementSummary = $statementService->summary($this->financialBroker, $periodStart, $periodEnd);
            $statementEntries = $statementService->entries($this->financialBroker, $periodStart, $periodEnd);
            $allAdvances = $this->financialBroker->advances()->with('bankAccount')->orderByDesc('date')->get();
            $allCommissions = $this->financialBroker->commissions()->with('caseType', 'bankAccount', 'settlements', 'payments')->orderByDesc('reference_date')->get();
            $allPayments = $this->financialBroker->commissionPayments()
                ->with('commission.caseType', 'bankAccount')
                ->orderByDesc('paid_at')
                ->get();
            $openCommissions = $this->financialBroker->commissions()
                ->with('caseType', 'settlements', 'payments')
                ->orderByDesc('reference_date')
                ->get()
                ->filter(fn (BrokerCommission $commission) => $commission->remainingAmount() > 0)
                ->values();
        }

        return [
            ...compact('advanceBalance', 'commissionSummary', 'allAdvances', 'allCommissions', 'allPayments'),
            'statementSummary' => $statementSummary,
            'statementEntries' => $statementEntries,
            'openCommissions' => $openCommissions,
            'caseTypes' => CaseType::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }

    private function resetLaunchForm(): void
    {
        $this->reset([
            'showLaunchModal',
            'launch_amount',
            'launch_base_amount',
            'launch_case_type_id',
            'launch_commission_id',
            'launch_bank_account_id',
            'launch_notes',
        ]);

        $this->launch_type = 'advance';
        $this->launch_date = now()->format('Y-m-d');
        $this->launch_payment_method = 'PIX';
        $this->resetErrorBag();
    }

    private function normalizeLaunchAmounts(): void
    {
        $this->launch_amount = $this->normalizeMoney($this->launch_amount);
        $this->launch_base_amount = $this->normalizeMoney($this->launch_base_amount);
    }

    private function normalizeMoney(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        $value = preg_replace('/[^\d,.-]/', '', $value) ?: '';

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return (string) (float) $value;
    }
}; ?>

<x-slot name="header">{{ $broker->name }}</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    @if ($financialBroker)
        <div class="flex flex-wrap justify-end gap-xs">
            <button type="button" wire:click="openLaunchModal('payment')" class="fx-btn fx-btn--standard fx-btn--sm gap-xs">
                <span class="material-icons-outlined text-base">payments</span>
                Registrar repasse
            </button>
            <button type="button" wire:click="openLaunchModal('commission')" class="fx-btn fx-btn--standard fx-btn--sm gap-xs border-primary-500 text-primary-500 hover:bg-primary-100">
                <span class="material-icons-outlined text-base">add</span>
                Registrar comissão
            </button>
            <button type="button" wire:click="openLaunchModal('advance')" class="fx-btn fx-btn--primary fx-btn--sm gap-xs">
                <span class="material-icons-outlined text-base">add</span>
                Novo adiantamento
            </button>
        </div>
    @endif

    @php
        $phoneList = array_values(array_filter($broker->phones ?: [$broker->phone]));
        $emailList = array_values(array_filter($broker->emails ?: [$broker->email]));
        $cityState = $broker->city ? trim($broker->city.' / '.$broker->state) : '—';
    @endphp

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
                <a href="{{ route('contacts.edit', $broker) }}" class="fx-btn fx-btn--standard fx-btn--sm">
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

    <x-fx.card>
        <div class="flex flex-col gap-sm lg:flex-row lg:items-end lg:justify-between">
            <div>
                <div class="text-sm font-semibold text-mono-900">Período dos indicadores</div>
                <div class="mt-xxs text-xs text-mono-600">Os saldos em aberto mostram a posição atual; os demais valores respeitam o intervalo.</div>
            </div>
            <div class="grid grid-cols-1 gap-xs sm:grid-cols-3 lg:w-[640px]">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Atalho</label>
                    <select wire:model.live="period" class="fx-form-field">
                        <option value="all">Todo histórico</option>
                        <option value="month">Mês atual</option>
                        <option value="year">Ano atual</option>
                        <option value="custom">Personalizado</option>
                    </select>
                </div>
                <x-fx.input label="Início" type="date" wire:model.live="start_date" wire:change="$set('period', 'custom')" />
                <x-fx.input label="Fim" type="date" wire:model.live="end_date" wire:change="$set('period', 'custom')" />
            </div>
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-md">
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-primary-100 bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-2xl">account_balance_wallet</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Adiantado no período</div>
                    <div class="mt-xxs truncate text-xl font-bold text-mono-900">R$ {{ number_format($statementSummary['advanced_period'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-down-bg bg-down-bg text-down">
                    <span class="material-icons-outlined text-2xl">balance</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Adiantado pendente</div>
                    <div class="mt-xxs truncate text-xl font-bold {{ $statementSummary['advance_pending'] > 0 ? 'text-down' : 'text-up' }}">R$ {{ number_format($statementSummary['advance_pending'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-primary-100 bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-2xl">percent</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Comissões geradas</div>
                    <div class="mt-xxs truncate text-xl font-bold text-mono-900">R$ {{ number_format($statementSummary['commissions_period'], 2, ',', '.') }}</div>
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
                    <div class="mt-xxs truncate text-xl font-bold text-up">R$ {{ number_format($statementSummary['settled_period'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-primary-100 bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-2xl">payments</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Repassado</div>
                    <div class="mt-xxs truncate text-xl font-bold text-mono-900">R$ {{ number_format($statementSummary['repassed_period'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
        <x-fx.card>
            <div class="flex items-center gap-sm">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-primary-100 bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-2xl">request_quote</span>
                </div>
                <div class="min-w-0">
                    <div class="text-xxs text-mono-600 uppercase">Saldo a pagar</div>
                    <div class="mt-xxs truncate text-xl font-bold {{ $statementSummary['commission_pending'] > 0 ? 'text-down' : 'text-up' }}">R$ {{ number_format($statementSummary['commission_pending'], 2, ',', '.') }}</div>
                </div>
            </div>
        </x-fx.card>
    </div>

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
                    <span class="min-w-24 text-mono-600">Telefones</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $phoneList ? implode(' / ', $phoneList) : '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">mail</span>
                    <span class="min-w-24 text-mono-600">E-mails</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $emailList ? implode(' / ', $emailList) : '—' }}</span>
                </div>
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">location_city</span>
                    <span class="min-w-24 text-mono-600">Cidade/UF</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $cityState }}</span>
                </div>
                <div class="flex items-center gap-xs sm:col-span-2">
                    <span class="material-icons-outlined text-lg text-mono-400">location_on</span>
                    <span class="min-w-24 text-mono-600">Endereço</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->street ?: ($broker->address ?: '—') }}{{ $broker->number ? ', '.$broker->number : '' }}{{ $broker->complement ? ' - '.$broker->complement : '' }}</span>
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
                <div class="flex items-center gap-xs">
                    <span class="material-icons-outlined text-lg text-mono-400">key</span>
                    <span class="min-w-24 text-mono-600">Tipo PIX</span>
                    <span class="min-w-0 break-words font-medium text-mono-900">{{ $broker->pixKeyTypeLabel() }}</span>
                </div>
                <div class="flex items-center gap-xs">
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

    <x-fx.card>
        @php
            $tab = 'inline-flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-semibold transition-colors -mb-px';
            $activeTab = 'border-primary-500 text-primary-500';
            $inactiveTab = 'border-transparent text-mono-600 hover:border-mono-200 hover:text-mono-900';
        @endphp

        <div class="mb-sm flex flex-col gap-sm sm:flex-row sm:items-end sm:justify-between">
            <nav class="flex flex-wrap gap-6 border-b border-mono-100">
                <button type="button" wire:click="$set('records_tab', 'statement')" class="{{ $tab }} {{ $records_tab === 'statement' ? $activeTab : $inactiveTab }}">
                    <span class="material-icons-outlined text-[18px]">receipt_long</span>
                    Extrato
                </button>
                <button type="button" wire:click="$set('records_tab', 'advances')" class="{{ $tab }} {{ $records_tab === 'advances' ? $activeTab : $inactiveTab }}">
                    <span class="material-icons-outlined text-[18px]">history</span>
                    Adiantamentos
                </button>
                <button type="button" wire:click="$set('records_tab', 'commissions')" class="{{ $tab }} {{ $records_tab === 'commissions' ? $activeTab : $inactiveTab }}">
                    <span class="material-icons-outlined text-[18px]">percent</span>
                    Comissões
                </button>
                <button type="button" wire:click="$set('records_tab', 'payments')" class="{{ $tab }} {{ $records_tab === 'payments' ? $activeTab : $inactiveTab }}">
                    <span class="material-icons-outlined text-[18px]">payments</span>
                    Repasses
                </button>
            </nav>

            @if ($records_tab === 'statement')
                <div class="text-xs font-medium text-mono-600">
                    Saída no período: R$ {{ number_format($statementSummary['cash_out_period'], 2, ',', '.') }}
                </div>
            @endif
        </div>

        @if ($records_tab === 'statement')
            @if ($statementEntries->isEmpty())
                <div class="flex min-h-24 items-center justify-center gap-sm rounded-lg bg-mono-50 px-sm py-md">
                    <span class="material-icons-outlined text-4xl text-mono-400">inventory_2</span>
                    <div>
                        <div class="text-sm font-semibold text-mono-900">Nenhum movimento no período.</div>
                        <div class="text-xs text-mono-600">Adiantamentos, comissões, compensações e repasses aparecerão aqui.</div>
                    </div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="fx-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-left">Data</th>
                                <th class="text-left">Movimento</th>
                                <th class="text-left">Descrição</th>
                                <th class="text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($statementEntries as $entry)
                                <tr>
                                    <td>{{ $entry['date']->format('d/m/Y') }}</td>
                                    <td>
                                        <span class="inline-flex items-center gap-xxs font-semibold text-mono-900">
                                            <span class="material-icons-outlined text-base {{ $entry['tone'] === 'up' ? 'text-up' : ($entry['tone'] === 'down' ? 'text-down' : 'text-primary-500') }}">{{ $entry['icon'] }}</span>
                                            {{ $entry['type'] }}
                                        </span>
                                    </td>
                                    <td class="text-mono-600">{{ $entry['description'] }}</td>
                                    <td class="text-right font-semibold {{ $entry['tone'] === 'up' ? 'text-up' : ($entry['tone'] === 'down' ? 'text-down' : 'text-mono-900') }}">
                                        R$ {{ number_format($entry['amount'], 2, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif ($records_tab === 'advances')
            @if ($allAdvances->isEmpty())
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
                        <thead>
                            <tr>
                                <th class="text-left">Data</th>
                                <th class="text-right">Valor</th>
                                <th class="text-left">Forma pgto</th>
                                <th class="text-left">Conta</th>
                                <th class="text-right">Compensado</th>
                                <th class="text-right">Saldo</th>
                                <th class="text-left">Notas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allAdvances as $adv)
                                <tr>
                                    <td>{{ $adv->date->format('d/m/Y') }}</td>
                                    <td class="text-right font-semibold">R$ {{ number_format($adv->amount, 2, ',', '.') }}</td>
                                    <td>{{ $adv->payment_method ?: '—' }}</td>
                                    <td>{{ $adv->bankAccount?->name ?: '—' }}</td>
                                    <td class="text-right text-up">R$ {{ number_format($adv->settledAmount(), 2, ',', '.') }}</td>
                                    <td class="text-right {{ $adv->remainingBalance() > 0 ? 'text-down font-semibold' : 'text-up' }}">
                                        R$ {{ number_format($adv->remainingBalance(), 2, ',', '.') }}
                                    </td>
                                    <td class="max-w-[200px] truncate text-xxs text-mono-600">{{ $adv->notes ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif ($records_tab === 'commissions')
            @if ($allCommissions->isEmpty())
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
                        <thead>
                            <tr>
                                <th class="text-left">Data ref.</th>
                                <th class="text-left">Tipo de caso</th>
                                <th class="text-right">Comissão</th>
                                <th class="text-right">Compensado</th>
                                <th class="text-right">Repassado</th>
                                <th class="text-right">Saldo</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allCommissions as $com)
                                <tr>
                                    <td>{{ $com->reference_date->format('d/m/Y') }}</td>
                                    <td>{{ $com->caseType->name }}</td>
                                    <td class="text-right font-semibold">R$ {{ number_format($com->commission_amount, 2, ',', '.') }}</td>
                                    <td class="text-right text-up">R$ {{ number_format($com->settledAmount(), 2, ',', '.') }}</td>
                                    <td class="text-right">R$ {{ number_format($com->paidAmount(), 2, ',', '.') }}</td>
                                    <td class="text-right {{ $com->remainingAmount() > 0 ? 'font-semibold text-down' : 'text-up' }}">R$ {{ number_format($com->remainingAmount(), 2, ',', '.') }}</td>
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
        @elseif ($records_tab === 'payments')
            @if ($allPayments->isEmpty())
                <div class="flex min-h-32 flex-col items-center justify-center rounded-lg border border-dashed border-mono-200 bg-mono-50 px-sm py-lg text-center">
                    <span class="material-icons-outlined mb-xs text-5xl text-mono-400">payments</span>
                    <div class="text-sm font-medium text-mono-900">Nenhum repasse registrado.</div>
                    <div class="mt-xxs text-xs text-mono-600">Os repasses em dinheiro ao corretor aparecerão aqui.</div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="fx-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-left">Data</th>
                                <th class="text-left">Tipo de caso</th>
                                <th class="text-left">Conta</th>
                                <th class="text-right">Valor</th>
                                <th class="text-left">Notas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allPayments as $payment)
                                <tr>
                                    <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                                    <td>{{ $payment->commission?->caseType?->name ?? '—' }}</td>
                                    <td>{{ $payment->bankAccount?->name ?? '—' }}</td>
                                    <td class="text-right font-semibold text-down">R$ {{ number_format($payment->amount, 2, ',', '.') }}</td>
                                    <td class="max-w-[200px] truncate text-xxs text-mono-600">{{ $payment->notes ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-fx.card>

    @if ($showLaunchModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelLaunchModal" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <div>
                        <h3 class="text-lg font-bold text-mono-900">Novo lançamento</h3>
                        <p class="text-xs text-mono-600">{{ $broker->name }}</p>
                    </div>

                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancelLaunchModal" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="saveLaunch" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">receipt_long</span>
                                    <h4 class="text-base font-bold text-mono-900">Identificação</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Movimento</label>
                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                            <button type="button" wire:click="$set('launch_type', 'advance')" class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $launch_type === 'advance' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}">Adiantamento</button>
                                            <button type="button" wire:click="$set('launch_type', 'commission')" class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $launch_type === 'commission' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}">Comissão</button>
                                            <button type="button" wire:click="$set('launch_type', 'payment')" class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $launch_type === 'payment' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}">Repasse</button>
                                        </div>
                                        @error('launch_type') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <x-jr.input label="Data" icon="event" type="date" name="launch_date" wire:model="launch_date" />
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">notes</span>
                                    <h4 class="text-base font-bold text-mono-900">Detalhes</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    @if ($launch_type === 'commission')
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Tipo de caso</label>
                                            <select wire:model="launch_case_type_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                                <option value="">Selecione</option>
                                                @foreach ($caseTypes as $caseType)
                                                    <option value="{{ $caseType->id }}">{{ $caseType->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('launch_case_type_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                            @if ($caseTypes->isEmpty())
                                                <p class="mt-2 text-xs text-mono-600">
                                                    Nenhum tipo de caso cadastrado.
                                                    <a href="{{ route('brokers.tipos-caso.index') }}" class="font-semibold text-primary-500 hover:text-primary-600">Cadastrar tipos de caso</a>
                                                </p>
                                            @endif
                                        </div>
                                        <x-jr.input label="Valor da comissão" icon="attach_money" type="text" name="launch_amount" x-money wire:model="launch_amount" />
                                    @endif

                                    @if ($launch_type === 'advance')
                                        <x-jr.input label="Valor adiantado" icon="attach_money" type="text" name="launch_amount" x-money wire:model="launch_amount" />
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Forma de pagamento</label>
                                            <select wire:model="launch_payment_method" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                                <option value="PIX">PIX</option>
                                                <option value="TED">TED</option>
                                                <option value="Dinheiro">Dinheiro</option>
                                                <option value="Cheque">Cheque</option>
                                                <option value="Outro">Outro</option>
                                            </select>
                                            @error('launch_payment_method') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>
                                    @endif

                                    @if ($launch_type === 'payment')
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Comissão em aberto</label>
                                            <select wire:model="launch_commission_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                                <option value="">Selecione</option>
                                                @foreach ($openCommissions as $commission)
                                                    <option value="{{ $commission->id }}">
                                                        {{ $commission->reference_date->format('d/m/Y') }} · {{ $commission->caseType->name }} · saldo R$ {{ number_format($commission->remainingAmount(), 2, ',', '.') }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('launch_commission_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                            @if ($openCommissions->isEmpty())
                                                <p class="mt-2 text-xs text-mono-600">Nenhuma comissão com saldo a pagar.</p>
                                            @endif
                                        </div>
                                        <x-jr.input label="Valor repassado" icon="attach_money" type="text" name="launch_amount" x-money wire:model="launch_amount" />
                                    @endif

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Conta bancária</label>
                                        <select wire:model="launch_bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                            <option value="">Nenhuma conta</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('launch_bank_account_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Observações</label>
                                        <textarea wire:model="launch_notes" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" rows="3"></textarea>
                                        @error('launch_notes') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancelLaunchModal">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            Salvar lançamento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
