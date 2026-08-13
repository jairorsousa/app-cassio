<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Services\BrokerAdvanceService;
use App\Domains\Brokers\Services\BrokerBalanceCalculator;
use App\Domains\Brokers\Models\BrokerCommissionPayment;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Models\CaseType;
use App\Domains\Brokers\Services\BrokerCommissionService;
use App\Domains\Brokers\Services\BrokerProfileService;
use App\Domains\Contacts\Models\Contact;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';
    #[Url]
    public string $status = '';

    public bool $showLaunchModal = false;
    public string $launch_type = 'advance';
    public ?int $launch_contact_id = null;
    public string $launch_date = '';
    public string $launch_amount = '';
    public string $launch_base_amount = '';
    public ?int $launch_case_type_id = null;
    public string $launch_name = '';
    public ?int $launch_commission_id = null;
    public string $launch_payment_method = 'PIX';
    public ?int $launch_bank_account_id = null;
    public string $launch_notes = '';

    public function openLaunchModal(?int $contactId = null, string $type = 'advance'): void
    {
        $this->resetLaunchForm();
        $this->launch_contact_id = $contactId;
        $this->launch_type = $type;
        $this->showLaunchModal = true;
    }

    public function cancelLaunchModal(): void
    {
        $this->resetLaunchForm();
    }

    public function updatedLaunchContactId(): void
    {
        $this->launch_commission_id = null;
    }

    public function updatedLaunchType(): void
    {
        $this->launch_commission_id = null;
        $this->resetErrorBag();
    }

    public function saveLaunch(BrokerProfileService $profiles, BrokerAdvanceService $advances, BrokerCommissionService $commissions): void
    {
        $this->normalizeLaunchAmounts();

        $rules = [
            'launch_type' => 'required|in:advance,commission,payment',
            'launch_contact_id' => [
                'required',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('type', 'corretor')),
            ],
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
                'launch_name' => 'required|string|max:160',
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
        $contact = Contact::where('type', 'corretor')->findOrFail($data['launch_contact_id']);

        try {
            $financialBroker = $profiles->forContact($contact);

            if ($data['launch_type'] === 'advance') {
                $result = $advances->register([
                    'broker_id' => $financialBroker->id,
                    'date' => $data['launch_date'],
                    'amount' => $data['launch_amount'],
                    'payment_method' => $data['launch_payment_method'] ?: null,
                    'bank_account_id' => $data['launch_bank_account_id'] ?? null,
                    'notes' => $data['launch_notes'] ?: null,
                ]);

                session()->flash('status', BrokerAdvanceService::statusMessage($result));
            }

            if ($data['launch_type'] === 'commission') {
                $commission = $commissions->registerFixedAmount([
                    'broker_id' => $financialBroker->id,
                    'case_type_id' => $data['launch_case_type_id'],
                    'name' => $data['launch_name'],
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
                $commission = BrokerCommission::where('broker_id', $financialBroker->id)
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

            $this->resetLaunchForm();
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function resetLaunchForm(): void
    {
        $this->reset([
            'showLaunchModal',
            'launch_contact_id',
            'launch_amount',
            'launch_base_amount',
            'launch_case_type_id',
            'launch_name',
            'launch_commission_id',
            'launch_bank_account_id',
            'launch_notes',
        ]);

        $this->launch_type = 'advance';
        $this->launch_date = now()->format('Y-m-d');
        $this->launch_payment_method = 'PIX';
        $this->resetErrorBag();
    }

    public function delete(int $id): void
    {
        $contact = Contact::where('type', 'corretor')->findOrFail($id);

        if (! $contact->canBeDeleted()) {
            session()->flash('error', $contact->deletionBlockMessage());
            return;
        }

        $contact->delete();
        session()->flash('status', 'Contato corretor removido.');
    }

    public function with(BrokerProfileService $profiles, BrokerBalanceCalculator $balances): array
    {
        $totalAdvances = (float) BrokerAdvance::sum('amount');
        $totalSettledAdvances = (float) BrokerCommissionSettlement::sum('amount_offset');
        $totalCommissions = (float) BrokerCommission::sum('commission_amount');
        $totalRepassed = (float) BrokerCommissionPayment::sum('amount');
        $commissionPending = max($totalCommissions - $totalSettledAdvances - $totalRepassed, 0);

        $q = Contact::query()->where('type', 'corretor');
        if ($this->search) {
            $q->where(function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                      ->orWhere('document', 'like', '%'.$this->search.'%');
            });
        }
        if ($this->status !== '') {
            $q->where('status', $this->status === '1');
        }

        $selectedContact = $this->launch_contact_id
            ? Contact::where('type', 'corretor')->find($this->launch_contact_id)
            : null;
        $selectedFinancialBroker = $selectedContact ? $profiles->findForContact($selectedContact) : null;
        $openCommissions = collect();

        if ($selectedFinancialBroker) {
            $openCommissions = $selectedFinancialBroker->commissions()
                ->with('caseType', 'settlements', 'payments')
                ->orderByDesc('reference_date')
                ->get()
                ->filter(fn (BrokerCommission $commission) => $commission->remainingAmount() > 0)
                ->values();
        }

        $brokers = $q->with('brokerProfile')->orderBy('name')->paginate(25);
        $pendingByFinancialBroker = $balances->pendingBalancesFor(
            $brokers->getCollection()
                ->pluck('brokerProfile.id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $brokerBalances = [];
        foreach ($brokers as $broker) {
            $financialId = $broker->brokerProfile?->id;
            $brokerBalances[$broker->id] = $pendingByFinancialBroker[$financialId] ?? [
                'advance_pending' => 0.0,
                'commission_pending' => 0.0,
            ];
        }

        return [
            'brokers' => $brokers,
            'brokerBalances' => $brokerBalances,
            'brokerOptions' => Contact::where('type', 'corretor')->orderBy('name')->get(['id', 'name', 'document']),
            'caseTypes' => CaseType::active()->orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'openCommissions' => $openCommissions,
            'summary' => [
                'total_brokers' => Contact::where('type', 'corretor')->count(),
                'active_brokers' => Contact::where('type', 'corretor')->where('status', true)->count(),
                'total_commissions' => $totalCommissions,
                'repassed_commissions' => $totalRepassed,
                'commission_pending' => $commissionPending,
                'total_advances' => $totalAdvances,
                'open_advances' => max($totalAdvances - $totalSettledAdvances, 0),
            ],
        ];
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

<x-slot name="header">Corretores</x-slot>

<div class="flex flex-col gap-md">
    <x-brokers.subnav />

    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    @if (session('error'))
        <x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex flex-wrap items-end gap-xs">
            <x-fx.input label="Buscar" wire:model.live.debounce.500ms="search" placeholder="Nome ou CPF/CNPJ..." />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Status</label>
                <select wire:model.live="status" class="fx-form-field">
                    <option value="">Todos</option>
                    <option value="1">Ativos</option>
                    <option value="0">Inativos</option>
                </select>
            </div>
            <button type="button" wire:click="openLaunchModal" class="fx-btn fx-btn--primary fx-btn--sm">
                <span class="material-icons-outlined text-base">add</span>
                Novo lançamento
            </button>
        </div>
    </x-fx.card>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-md">
        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Corretores cadastrados</div>
                    <div class="mt-xxs text-xl font-bold">{{ number_format($summary['total_brokers'], 0, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-primary-500 text-lg">groups</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">{{ number_format($summary['active_brokers'], 0, ',', '.') }} ativos</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Comissões cadastradas</div>
                    <div class="mt-xxs text-xl font-bold">R$ {{ number_format($summary['total_commissions'], 2, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-primary-500 text-lg">request_quote</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Total lançado no histórico</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Repassado</div>
                    <div class="mt-xxs text-xl font-bold text-system-up">R$ {{ number_format($summary['repassed_commissions'], 2, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-system-up text-lg">paid</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Dinheiro pago ao corretor</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Comissão adiantada</div>
                    <div class="mt-xxs text-xl font-bold">R$ {{ number_format($summary['total_advances'], 2, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-primary-500 text-lg">payments</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Total de adiantamentos</div>
        </x-fx.card>

        <x-fx.card>
            <div class="flex items-start justify-between gap-xs">
                <div>
                    <div class="text-xxs text-mono-600 uppercase">Saldo a pagar</div>
                    <div class="mt-xxs text-xl font-bold {{ $summary['commission_pending'] > 0 ? 'text-system-down' : 'text-system-up' }}">
                        R$ {{ number_format($summary['commission_pending'], 2, ',', '.') }}
                    </div>
                </div>
                <span class="material-icons-outlined {{ $summary['commission_pending'] > 0 ? 'text-system-down' : 'text-system-up' }} text-lg">balance</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Comissões ainda abertas</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        @if ($brokers->isEmpty())
            <div class="text-sm text-mono-600">Nenhum corretor encontrado.</div>
        @else
            <div class="overflow-x-auto">
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Documento</th>
                        <th class="text-left">Telefone</th>
                        <th class="text-right">Saldo Adiantamento</th>
                        <th class="text-right">Saldo Corretor</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($brokers as $broker)
                        @php
                            $advancePending = (float) ($brokerBalances[$broker->id]['advance_pending'] ?? 0);
                            $commissionPending = (float) ($brokerBalances[$broker->id]['commission_pending'] ?? 0);
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('brokers.show', $broker) }}" class="font-medium hover:text-primary-500">{{ $broker->name }}</a>
                            </td>
                            <td>{{ $broker->document ?: '—' }}</td>
                            <td>{{ $broker->phone ?: '—' }}</td>
                            <td class="text-right tabular-nums font-semibold">
                                <span class="{{ $advancePending > 0 ? 'text-down' : 'text-mono-900' }}">
                                    R$ {{ number_format($advancePending, 2, ',', '.') }}
                                </span>
                            </td>
                            <td class="text-right tabular-nums font-semibold">
                                <span class="{{ $commissionPending > 0 ? 'text-up' : 'text-mono-900' }}">
                                    R$ {{ number_format($commissionPending, 2, ',', '.') }}
                                </span>
                            </td>
                            <td class="text-center">
                                <x-fx.badge :variant="$broker->status ? 'up' : 'neutral'">{{ $broker->status ? 'Ativo' : 'Inativo' }}</x-fx.badge>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-xxs">
                                    <a href="{{ route('contacts.edit', $broker) }}" class="fx-btn fx-btn--text fx-btn--sm">Editar</a>
                                    <a href="{{ route('brokers.show', $broker) }}" class="fx-btn fx-btn--text fx-btn--sm">Ver</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div class="mt-md">{{ $brokers->links() }}</div>
        @endif
    </x-fx.card>

    @if ($showLaunchModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelLaunchModal" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <div>
                        <h3 class="text-lg font-bold text-mono-900">Novo lançamento</h3>
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
                                    <div class="md:col-span-3">
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Movimento</label>
                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                            <button
                                                type="button"
                                                wire:click="$set('launch_type', 'advance')"
                                                class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $launch_type === 'advance' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                            >
                                                Adiantamento
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="$set('launch_type', 'commission')"
                                                class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $launch_type === 'commission' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                            >
                                                Comissão
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="$set('launch_type', 'payment')"
                                                class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $launch_type === 'payment' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                            >
                                                Repasse
                                            </button>
                                        </div>
                                        @error('launch_type') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Corretor</label>
                                        <select wire:model.live="launch_contact_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                            <option value="">Selecione</option>
                                            @foreach ($brokerOptions as $option)
                                                <option value="{{ $option->id }}">{{ $option->name }}{{ $option->document ? ' · '.$option->document : '' }}</option>
                                            @endforeach
                                        </select>
                                        @error('launch_contact_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
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
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Nome</label>
                                            <input
                                                type="text"
                                                wire:model="launch_name"
                                                placeholder="Ex. cliente ou processo"
                                                class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 placeholder:text-mono-300 focus:border-primary-500 focus:ring-0"
                                            />
                                            @error('launch_name') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
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
                                                        {{ $commission->reference_date?->format('d/m/Y') }}
                                                        · {{ $commission->caseType?->name ?: 'Sem tipo' }}
                                                        @if ($commission->name) · {{ $commission->name }} @endif
                                                        · saldo R$ {{ number_format($commission->remainingAmount(), 2, ',', '.') }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('launch_commission_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                            @if ($launch_contact_id && $openCommissions->isEmpty())
                                                <p class="mt-2 text-xs text-mono-600">Nenhuma comissão com saldo a pagar para este corretor.</p>
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
