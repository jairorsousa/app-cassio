<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Services\TransactionService;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Contacts\Models\Contact;
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

    public bool $showTransactionModal = false;
    public string $transaction_type = 'expense';
    public string $transaction_date = '';
    public string $transaction_amount = '';
    public string $transaction_description = '';
    public ?int $transaction_category_id = null;
    public ?int $transaction_bank_account_id = null;
    public string $transaction_status = 'settled';
    public string $transaction_notes = '';

    public function openTransactionModal(): void
    {
        $this->resetTransactionForm();
        $this->showTransactionModal = true;
    }

    public function cancelTransactionModal(): void
    {
        $this->resetTransactionForm();
    }

    public function saveTransaction(TransactionService $service): void
    {
        $data = $this->validate([
            'transaction_type' => 'required|in:income,expense',
            'transaction_date' => 'required|date',
            'transaction_amount' => 'required|numeric|min:0.01',
            'transaction_description' => 'required|string|max:200',
            'transaction_category_id' => 'nullable|exists:categories,id',
            'transaction_bank_account_id' => 'nullable|exists:bank_accounts,id',
            'transaction_status' => 'required|in:pending,settled',
            'transaction_notes' => 'nullable|string',
        ]);

        $service->create([
            'type' => $data['transaction_type'],
            'date' => $data['transaction_date'],
            'amount' => $data['transaction_amount'],
            'description' => $data['transaction_description'],
            'notes' => $data['transaction_notes'] ?: null,
            'status' => $data['transaction_status'],
            'category_id' => $data['transaction_category_id'],
            'bank_account_id' => $data['transaction_bank_account_id'],
        ]);

        $this->resetTransactionForm();
        session()->flash('status', 'Lançamento criado.');
    }

    private function resetTransactionForm(): void
    {
        $this->reset([
            'showTransactionModal',
            'transaction_amount',
            'transaction_description',
            'transaction_category_id',
            'transaction_bank_account_id',
            'transaction_notes',
        ]);

        $this->transaction_type = 'expense';
        $this->transaction_date = now()->format('Y-m-d');
        $this->transaction_status = 'settled';
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

    public function with(): array
    {
        $totalAdvances = (float) BrokerAdvance::sum('amount');
        $totalSettledAdvances = (float) BrokerCommissionSettlement::sum('amount_offset');

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

        return [
            'brokers' => $q->orderBy('name')->paginate(25),
            'categories' => Category::active()
                ->where('type', $this->transaction_type)
                ->orderBy('name')
                ->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'summary' => [
                'total_brokers' => Contact::where('type', 'corretor')->count(),
                'active_brokers' => Contact::where('type', 'corretor')->where('status', true)->count(),
                'total_commissions' => (float) BrokerCommission::sum('commission_amount'),
                'paid_commissions' => (float) BrokerCommission::where('status', 'paid')->sum('commission_amount'),
                'total_advances' => $totalAdvances,
                'open_advances' => max($totalAdvances - $totalSettledAdvances, 0),
            ],
        ];
    }
}; ?>

<x-slot name="header">Corretores</x-slot>

<div class="flex flex-col gap-md">
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
            <button type="button" wire:click="openTransactionModal" class="fx-btn fx-btn--primary fx-btn--sm">
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
                    <div class="text-xxs text-mono-600 uppercase">Comissões pagas</div>
                    <div class="mt-xxs text-xl font-bold text-system-up">R$ {{ number_format($summary['paid_commissions'], 2, ',', '.') }}</div>
                </div>
                <span class="material-icons-outlined text-system-up text-lg">paid</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Marcadas como pagas</div>
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
                    <div class="text-xxs text-mono-600 uppercase">A compensar</div>
                    <div class="mt-xxs text-xl font-bold {{ $summary['open_advances'] > 0 ? 'text-system-down' : 'text-system-up' }}">
                        R$ {{ number_format($summary['open_advances'], 2, ',', '.') }}
                    </div>
                </div>
                <span class="material-icons-outlined {{ $summary['open_advances'] > 0 ? 'text-system-down' : 'text-system-up' }} text-lg">account_balance_wallet</span>
            </div>
            <div class="mt-xs text-xs text-mono-600">Adiantamentos em aberto</div>
        </x-fx.card>
    </div>

    <x-fx.card>
        @if ($brokers->isEmpty())
            <div class="text-sm text-mono-600">Nenhum corretor encontrado.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Documento</th>
                        <th class="text-left">Telefone</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($brokers as $broker)
                        <tr>
                            <td>
                                <a href="{{ route('brokers.show', $broker) }}" class="font-medium hover:text-primary-500">{{ $broker->name }}</a>
                            </td>
                            <td>{{ $broker->document ?: '—' }}</td>
                            <td>{{ $broker->phone ?: '—' }}</td>
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
            <div class="mt-md">{{ $brokers->links() }}</div>
        @endif
    </x-fx.card>

    @if ($showTransactionModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancelTransactionModal" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <div>
                        <h3 class="text-lg font-bold text-mono-900">Novo lançamento</h3>
                    </div>

                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancelTransactionModal" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="saveTransaction" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">receipt_long</span>
                                    <h4 class="text-base font-bold text-mono-900">Identificação</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Tipo</label>
                                        <div class="grid grid-cols-2 gap-3">
                                            <button
                                                type="button"
                                                wire:click="$set('transaction_type', 'expense')"
                                                class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $transaction_type === 'expense' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                            >
                                                Despesa
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="$set('transaction_type', 'income')"
                                                class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $transaction_type === 'income' ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                            >
                                                Receita
                                            </button>
                                        </div>
                                        @error('transaction_type') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <x-jr.input label="Data" icon="event" type="date" name="transaction_date" wire:model="transaction_date" />
                                    <x-jr.input label="Valor" icon="attach_money" type="text" name="transaction_amount" x-money wire:model="transaction_amount" />
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">notes</span>
                                    <h4 class="text-base font-bold text-mono-900">Detalhes</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div class="md:col-span-2">
                                        <x-jr.input label="Descrição" icon="edit_note" name="transaction_description" wire:model="transaction_description" />
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Categoria</label>
                                        <select wire:model="transaction_category_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                            <option value="">Sem categoria</option>
                                            @foreach ($categories as $category)
                                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('transaction_category_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Conta bancária</label>
                                        <select wire:model="transaction_bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                            <option value="">Nenhuma conta</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('transaction_bank_account_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Status</label>
                                        <select wire:model="transaction_status" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                            <option value="settled">Liquidado</option>
                                            <option value="pending">Pendente</option>
                                        </select>
                                        @error('transaction_status') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Observações</label>
                                        <textarea wire:model="transaction_notes" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" rows="3"></textarea>
                                        @error('transaction_notes') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancelTransactionModal">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            Criar lançamento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
