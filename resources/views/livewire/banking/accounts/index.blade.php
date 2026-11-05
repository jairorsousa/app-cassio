<?php

use App\Domains\Banking\Models\BankAccount;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public bool $showFormModal = false;

    public ?int $repasseAccountId = null;

    public function manageRepasses(int $id): void
    {
        $this->repasseAccountId = BankAccount::where('type', 'repasse')->findOrFail($id)->id;
    }

    #[\Livewire\Attributes\On('repasses-closed')]
    public function closeRepasses(): void
    {
        $this->repasseAccountId = null;
    }

    public ?int $editingId = null;

    public string $name = '';

    public string $bank = '';

    public string $agency = '';

    public string $number = '';

    public string $type = 'checking';

    public string $initial_balance = '0';

    public string $initial_balance_date = '';

    public ?int $linked_account_id = null;

    public bool $showCheckModal = false;

    public ?int $checkAccountId = null;

    public string $checkDate = '';

    public string $checkStatement = '';

    public ?array $checkResult = null;

    public bool $status = true;

    public string $notes = '';

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'bank' => 'nullable|string|max:120',
            'agency' => 'nullable|string|max:20',
            'number' => 'nullable|string|max:30',
            'type' => 'required|in:checking,savings,investment,fund,cash,repasse',
            'initial_balance' => 'required|numeric',
            'initial_balance_date' => 'nullable|date_format:Y-m-d',
            'linked_account_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('bank_accounts', 'id')->whereIn('type', ['checking', 'savings'])->whereNull('deleted_at'), 'not_in:'.($this->editingId ?? 0)],
            'status' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }

    public function edit(int $id): void
    {
        $a = BankAccount::findOrFail($id);
        $this->editingId = $a->id;
        $this->name = $a->name;
        $this->bank = (string) $a->bank;
        $this->agency = (string) $a->agency;
        $this->number = (string) $a->number;
        $this->type = $a->type;
        $this->initial_balance = (string) $a->initial_balance;
        $this->initial_balance_date = $a->initial_balance_date?->format('Y-m-d') ?? '';
        $this->linked_account_id = $a->linked_account_id;
        $this->status = (bool) $a->status;
        $this->notes = (string) $a->notes;
        $this->showFormModal = true;
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();
        $data['initial_balance_date'] = $data['initial_balance_date'] ?: null;
        // Só conta de investimento pode ser vinculada a uma conta corrente.
        $data['linked_account_id'] = in_array($data['type'], ['investment', 'fund'], true) ? ($data['linked_account_id'] ?: null) : null;

        $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            if ($this->editingId) {
                $account = BankAccount::lockForUpdate()->findOrFail($this->editingId);
                if ($data['type'] !== 'repasse' && \App\Domains\Banking\Models\ReceiptDestination::where('repasse_account_id', $account->id)->exists()) {
                    $this->addError('type', 'Remova as divisões vinculadas antes de alterar o tipo desta conta de repasse.');
                    return false;
                }
                $account->update($data);
            } else {
                BankAccount::create($data);
            }
            return true;
        });
        if (! $saved) { return; }

        $this->resetForm();
        session()->flash('status', 'Conta salva.');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $deleted = \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
            $account = BankAccount::lockForUpdate()->find($id);
            if (\App\Domains\Banking\Models\ReceiptDestination::where('repasse_account_id', $id)->exists()) {
                session()->flash('error', 'Remova as divisões vinculadas antes de excluir esta conta de repasse.');
                return false;
            }
            $account?->delete();
            return true;
        });
        if ($deleted) { session()->flash('status', 'Conta excluída.'); }
    }

    private function resetForm(): void
    {
        $this->reset(['showFormModal', 'editingId', 'name', 'bank', 'agency', 'number', 'type', 'initial_balance', 'initial_balance_date', 'linked_account_id', 'status', 'notes']);
        $this->type = 'checking';
        $this->initial_balance = '0';
        $this->status = true;
        $this->resetValidation();
    }

    public function openCheck(?int $id = null): void
    {
        $this->resetValidation();
        $this->checkAccountId = $id;
        $this->checkDate = now('America/Sao_Paulo')->format('Y-m-d');
        $this->checkStatement = '';
        $this->checkResult = null;
        $this->showCheckModal = true;
    }

    public function closeCheck(): void
    {
        $this->reset(['showCheckModal', 'checkAccountId', 'checkDate', 'checkStatement', 'checkResult']);
        $this->resetValidation();
    }

    /** Conferir saldo: compara o saldo do extrato numa data com o saldo calculado pelo sistema. */
    public function runCheck(): void
    {
        if (str_contains($this->checkStatement, ',')) {
            $this->checkStatement = str_replace(',', '.', str_replace('.', '', $this->checkStatement));
        }
        $data = $this->validate([
            'checkAccountId' => 'required|integer|exists:bank_accounts,id',
            'checkDate' => 'required|date_format:Y-m-d',
            'checkStatement' => 'required|numeric',
        ]);
        $account = BankAccount::findOrFail($data['checkAccountId']);
        $calculated = round($account->balance($data['checkDate']), 2);
        $statement = round((float) $data['checkStatement'], 2);
        $this->checkResult = [
            'account' => $account->name,
            'date' => \Illuminate\Support\Carbon::parse($data['checkDate'])->format('d/m/Y'),
            'calculated' => $calculated,
            'statement' => $statement,
            'difference' => round($statement - $calculated, 2),
            'before_cut' => $account->isHistoricalDate($data['checkDate']),
        ];
    }

    public function with(): array
    {
        return [
            'accounts' => BankAccount::with('linkedAccount')->orderBy('name')->get(),
            'linkableAccounts' => BankAccount::whereIn('type', ['checking', 'savings'])->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro</x-slot>

<div class="flex flex-col gap-space-5">
    <x-banking.subnav />

    <p class="text-sm text-mono-600">As contas de repasse controlam valores de terceiros: o saldo negativo indica quanto ainda deve ser repassado. O dinheiro permanece na conta bancária até o pagamento.</p>

    <x-fx.card>
        <div class="mb-space-4 flex items-center justify-between">
            <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Contas</h3>
            <div class="flex items-center gap-2">
                <x-jr.button type="button" size="sm" wire:click="openCheck">
                    <span class="material-icons-outlined text-[18px]">fact_check</span>
                    Conferir saldo
                </x-jr.button>
                <x-jr.button type="button" size="sm" wire:click="create">
                    <span class="material-icons-outlined text-[18px]">add</span>
                    Nova conta
                </x-jr.button>
            </div>
        </div>

        @if ($accounts->isEmpty())
            <x-fx.empty-state
                icon="🏦"
                title="Nenhuma conta cadastrada"
                description="Cadastre suas contas correntes, poupança, dinheiro e repasse para começar a registrar lançamentos.">
                <x-jr.button type="button" class="mt-space-4" wire:click="create">
                    <span class="material-icons-outlined text-[18px]">add</span>
                    Nova conta
                </x-jr.button>
            </x-fx.empty-state>
        @else
            <x-fx.table :headers="['Nome', 'Banco', 'Tipo', 'Saldo', '']">
                @foreach ($accounts as $a)
                    <tr>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-primary whitespace-nowrap">{{ $a->name }} @unless($a->status)<span class="text-fs-12 text-cryptex-text-secondary ml-1">(inativa)</span>@endunless</td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ $a->bank }}</td>
                        <td class="px-space-4 py-space-3 text-fs-14 text-cryptex-text-secondary">{{ \App\Domains\Banking\Models\BankAccount::TYPE_LABELS[$a->type] ?? $a->type }}@if ($a->linkedAccount)<div class="text-fs-12">vinculada a {{ $a->linkedAccount->name }}</div>@endif @if ($a->initial_balance_date)<div class="text-fs-12">saldo inicial em {{ $a->initial_balance_date->format('d/m/Y') }}</div>@endif</td>
                        <td class="px-space-4 py-space-3 text-right font-medium font-mono whitespace-nowrap [font-variant-numeric:tabular-nums] {{ $a->balance() >= 0 ? 'text-cryptex-green-500' : 'text-cryptex-red-500' }}">R$ {{ number_format($a->balance(), 2, ',', '.') }}</td>
                        <td class="px-space-4 py-space-3 text-right whitespace-nowrap">
                            @if ($a->type === 'repasse')
                                <button type="button" class="mr-3 text-fs-12 font-semibold text-primary-600" wire:click="manageRepasses({{ $a->id }})">Gerenciar repasses</button>
                            @endif
                            <button type="button" class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3" wire:click="openCheck({{ $a->id }})">Conferir</button>
                            <button class="text-cryptex-brand-400 hover:text-cryptex-brand-300 font-medium text-fs-12 transition-colors mr-3" wire:click="edit({{ $a->id }})">Editar</button>
                            <button class="text-cryptex-red-400 hover:text-cryptex-red-500 font-medium text-fs-12 transition-colors" wire:click="delete({{ $a->id }})" wire:confirm="Excluir conta?">Excluir</button>
                        </td>
                    </tr>
                @endforeach
            </x-fx.table>
        @endif
    </x-fx.card>

    @if ($repasseAccountId)
        <livewire:banking.accounts.repasses :account-id="$repasseAccountId" :key="'repasses-'.$repasseAccountId" />
    @endif

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar Conta' : 'Nova Conta' }}</h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="space-y-8">
                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">account_balance</span>
                                    <h4 class="text-base font-bold text-mono-900">Dados da conta</h4>
                                </div>

                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <x-jr.input label="Nome *" icon="badge" name="name" wire:model="name" required />
                                    <x-jr.input label="Banco" icon="account_balance" name="bank" wire:model="bank" />
                                    <x-jr.input label="Agência" icon="confirmation_number" name="agency" wire:model="agency" />
                                    <x-jr.input label="Conta" icon="credit_card" name="number" wire:model="number" />
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Tipo *</label>
                                        <select wire:model.live="type" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                            <option value="checking">Corrente</option>
                                            <option value="savings">Poupança</option>
                                            <option value="investment">Investimento</option>
                                            <option value="fund">Fundo (aplicação automática)</option>
                                            <option value="cash">Dinheiro</option>
                                            <option value="repasse">Repasse</option>
                                        </select>
                                        @error('type') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                    </div>
                                    <x-jr.input label="Saldo inicial *" icon="payments" name="initial_balance" wire:model="initial_balance" x-money required />
                                    <div>
                                        <x-jr.input label="Data do saldo inicial" icon="calendar_month" name="initial_balance_date" type="date" wire:model="initial_balance_date" />
                                        <p class="mt-1 text-xs text-mono-600">Use o saldo do extrato nessa data. Lançamentos anteriores viram histórico: ficam nos módulos, mas não alteram o saldo nem o resultado.</p>
                                    </div>
                                    @if (in_array($type, ['investment', 'fund'], true))
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-mono-600">Conta corrente vinculada</label>
                                            <select wire:model="linked_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900">
                                                <option value="">Nenhuma</option>
                                                @foreach ($linkableAccounts as $option)
                                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                                @endforeach
                                            </select>
                                            <p class="mt-1 text-xs text-mono-600">Aplicação e resgate entre as duas são transferências. Rendimento é Receita na categoria "Rendimento de Investimentos".</p>
                                            @error('linked_account_id') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                                        </div>
                                    @endif
                                </div>
                            </section>

                            <section>
                                <div class="mb-4 flex items-center gap-2 border-b border-mono-100 pb-2">
                                    <span class="material-icons-outlined text-[20px] text-primary-500">tune</span>
                                    <h4 class="text-base font-bold text-mono-900">Outros</h4>
                                </div>

                                <div class="space-y-4">
                                    <label class="flex w-fit cursor-pointer items-center gap-3 text-sm">
                                        <input type="checkbox" wire:model="status" class="h-5 w-5 rounded border-mono-300 text-primary-500 focus:ring-primary-500">
                                        <span class="font-medium text-mono-900">Conta ativa</span>
                                    </label>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-mono-600">Observações</label>
                                        <textarea wire:model="notes" class="w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 placeholder:text-mono-300 transition-all focus:border-primary-500 focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]" rows="3"></textarea>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            <span class="material-icons-outlined text-[18px]">check</span>
                            {{ $editingId ? 'Salvar Conta' : 'Criar Conta' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
    @if ($showCheckModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="closeCheck" aria-label="Fechar modal"></button>
            <div class="relative w-full max-w-lg rounded-2xl border border-mono-100 bg-mono-white p-6 shadow-elevated">
                <h3 class="mb-4 text-lg font-bold text-mono-900">Conferir saldo com o extrato</h3>
                <form wire:submit="runCheck" class="space-y-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-mono-600">Conta *</label>
                        <select wire:model="checkAccountId" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900">
                            <option value="">Selecione</option>
                            @foreach ($accounts as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('checkAccountId') <p class="mt-2 text-xs font-medium text-error">{{ $message }}</p> @enderror
                    </div>
                    <x-jr.input label="Data do extrato *" icon="calendar_month" name="checkDate" type="date" wire:model="checkDate" required />
                    <x-jr.input label="Saldo no extrato *" icon="payments" name="checkStatement" wire:model="checkStatement" x-money required />
                    @if ($checkResult)
                        <div class="rounded-xl border border-mono-100 bg-mono-50 p-4 text-sm" data-test="check-result">
                            <p><strong>{{ $checkResult['account'] }}</strong> em {{ $checkResult['date'] }}</p>
                            <p>Saldo no sistema: R$ {{ number_format($checkResult['calculated'], 2, ',', '.') }}</p>
                            <p>Saldo no extrato: R$ {{ number_format($checkResult['statement'], 2, ',', '.') }}</p>
                            <p class="font-bold {{ abs($checkResult['difference']) < 0.005 ? 'text-system-up' : 'text-system-down' }}">
                                Diferença: R$ {{ number_format($checkResult['difference'], 2, ',', '.') }}
                                {{ abs($checkResult['difference']) < 0.005 ? '(saldo conferido)' : '(falta lançar ou há lançamento a mais)' }}
                            </p>
                            @if ($checkResult['before_cut'])
                                <p class="mt-1 text-xs text-mono-600">A data é anterior ao saldo inicial da conta; o sistema mostra apenas o saldo inicial.</p>
                            @endif
                        </div>
                    @endif
                    <div class="flex justify-end gap-3">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900" wire:click="closeCheck">Fechar</button>
                        <button type="submit" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white">Conferir</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

