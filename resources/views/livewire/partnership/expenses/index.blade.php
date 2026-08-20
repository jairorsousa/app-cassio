<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipExpense;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Partnership $partnership;

    public ?int $editingId = null;
    public bool $showFormModal = false;
    public string $expenseDate = '';
    public string $total_amount = '';
    public string $applied_percentage = '';
    public string $description = '';
    public ?int $category_id = null;
    public ?int $bank_account_id = null;
    public string $expenseNotes = '';

    public function mount(Partnership $partnership): void
    {
        $this->partnership = $partnership;
        $this->expenseDate = now()->format('Y-m-d');
        $this->applied_percentage = (string) $partnership->participation_percentage;
    }

    public function rules(): array
    {
        return [
            'expenseDate' => 'required|date',
            'total_amount' => 'required|numeric|min:0',
            'applied_percentage' => 'required|numeric|min:0|max:100',
            'description' => 'required|string|max:200',
            'category_id' => 'nullable|exists:categories,id',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'expenseNotes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Informe a conta para a despesa entrar no caixa.',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $e = $this->partnership->expenses()->findOrFail($id);
        $this->editingId = $e->id;
        $this->expenseDate = $e->date->format('Y-m-d');
        $this->total_amount = (string) $e->total_amount;
        $this->applied_percentage = (string) $e->applied_percentage;
        $this->description = $e->description;
        $this->category_id = $e->category_id;
        $this->bank_account_id = $e->bank_account_id;
        $this->expenseNotes = (string) $e->notes;
        $this->showFormModal = true;
    }

    public function proportionalPreview(): float
    {
        $total = (float) $this->total_amount;
        $pct = (float) $this->applied_percentage;
        return round($total * $pct / 100, 2);
    }

    public function save(): void
    {
        $data = $this->validate();
        $proportional = round((float) $data['total_amount'] * (float) $data['applied_percentage'] / 100, 2);

        $payload = [
            'partnership_id' => $this->partnership->id,
            'date' => $data['expenseDate'],
            'total_amount' => $data['total_amount'],
            'applied_percentage' => $data['applied_percentage'],
            'proportional_amount' => $proportional,
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'bank_account_id' => $data['bank_account_id'],
            'notes' => $data['expenseNotes'],
        ];

        if ($this->editingId) {
            $this->partnership->expenses()->find($this->editingId)?->update($payload);
        } else {
            PartnershipExpense::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Despesa salva.');
    }

    public function delete(int $id): void
    {
        $this->partnership->expenses()->find($id)?->delete();
        session()->flash('status', 'Despesa excluída.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'showFormModal', 'total_amount', 'description', 'category_id', 'bank_account_id', 'expenseNotes']);
        $this->expenseDate = now()->format('Y-m-d');
        $this->applied_percentage = (string) $this->partnership->participation_percentage;
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'expenses' => $this->partnership->expenses()->with('category', 'bankAccount')->orderByDesc('date')->get(),
            'categories' => Category::active()->where('type', 'expense')->orderBy('name')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">{{ $partnership->name }} · Despesas</x-slot>

<div class="flex flex-col gap-md">
    <x-partnership.subnav :partnership="$partnership" />

    <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-mono-900">Despesas</h2>
            <p class="mt-1 text-sm text-mono-600">Controle os custos da sociedade suportados proporcionalmente.</p>
        </div>
        <button type="button" class="fx-btn fx-btn--primary self-start sm:self-auto" wire:click="create">
            <span class="material-icons-outlined text-[18px]">add</span>
            Nova despesa
        </button>
    </div>

    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

    @if ($expenses->isEmpty())
        <x-jr.empty-state icon="receipt_long" title="Nenhuma despesa cadastrada" description="Registre despesas da sociedade e o sistema calculará sua parcela proporcional." />
    @else
        <x-fx.card class="p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="fx-table text-sm">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Conta</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Participação</th>
                            <th class="text-right">Valor do sócio</th>
                            <th class="w-20"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expenses as $e)
                            <tr>
                                <td class="whitespace-nowrap font-mono">{{ $e->date->format('d/m/Y') }}</td>
                                <td class="font-medium">{{ $e->description }}</td>
                                <td>{{ $e->category?->name ?? '—' }}</td>
                                <td>{{ $e->bankAccount?->name ?? '—' }}</td>
                                <td class="text-right whitespace-nowrap font-mono">R$ {{ number_format((float) $e->total_amount, 2, ',', '.') }}</td>
                                <td class="text-right whitespace-nowrap font-mono">{{ number_format((float) $e->applied_percentage, 2, ',', '.') }}%</td>
                                <td class="text-right whitespace-nowrap font-mono font-semibold text-system-down">R$ {{ number_format((float) $e->proportional_amount, 2, ',', '.') }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex justify-end gap-xxs">
                                        <button type="button" class="fx-btn fx-btn--icon h-9 w-9" wire:click="edit({{ $e->id }})" title="Editar despesa" aria-label="Editar despesa">
                                            <span class="material-icons-outlined text-[18px]">edit</span>
                                        </button>
                                        <button type="button" class="fx-btn fx-btn--icon h-9 w-9 text-error" wire:click="delete({{ $e->id }})" wire:confirm="Excluir despesa? O lançamento no caixa também será removido." title="Excluir despesa" aria-label="Excluir despesa">
                                            <span class="material-icons-outlined text-[18px]">delete</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-fx.card>
    @endif

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal flex items-center justify-center overflow-y-auto px-4 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex h-[66px] shrink-0 items-center justify-between border-b border-mono-100 px-6">
                    <div>
                        <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar despesa' : 'Nova despesa' }}</h3>
                        <p class="text-xs text-mono-600">{{ $partnership->name }}</p>
                    </div>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-jr.input label="Data" icon="event" type="date" name="expenseDate" wire:model="expenseDate" />
                            <x-jr.input label="Descrição" icon="description" name="description" wire:model="description" required />
                            <x-jr.input label="Valor total" icon="attach_money" type="text" name="total_amount" x-money wire:model.live="total_amount" />
                            <x-jr.input label="Participação aplicada (%)" icon="percent" type="number" name="applied_percentage" step="0.001" min="0" max="100" wire:model.live="applied_percentage" />

                            <div class="md:col-span-2 rounded-xl border border-primary-100 bg-primary-100 px-4 py-3">
                                <p class="text-xs font-medium text-mono-600">Valor proporcional para Dr. Cássio</p>
                                <p class="mt-1 text-lg font-bold font-mono text-primary-500">R$ {{ number_format($this->proportionalPreview(), 2, ',', '.') }}</p>
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-mono-600">Categoria</label>
                                <select wire:model="category_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0">
                                    <option value="">Sem categoria</option>
                                    @foreach ($categories as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                @error('category_id')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-mono-600">Conta do débito <span class="text-error">*</span></label>
                                <select wire:model="bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0" required>
                                    <option value="">Selecione</option>
                                    @foreach ($accounts as $a)
                                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                                    @endforeach
                                </select>
                                @error('bank_account_id')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-mono-600">Notas</label>
                                <textarea wire:model="expenseNotes" class="min-h-24 w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 focus:border-primary-500 focus:ring-0" rows="3"></textarea>
                                @error('expenseNotes')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="fx-btn fx-btn--primary">Salvar despesa</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
