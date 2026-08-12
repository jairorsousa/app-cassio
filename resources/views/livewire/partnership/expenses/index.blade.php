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

    public function edit(int $id): void
    {
        $e = PartnershipExpense::findOrFail($id);
        $this->editingId = $e->id;
        $this->expenseDate = $e->date->format('Y-m-d');
        $this->total_amount = (string) $e->total_amount;
        $this->applied_percentage = (string) $e->applied_percentage;
        $this->description = $e->description;
        $this->category_id = $e->category_id;
        $this->bank_account_id = $e->bank_account_id;
        $this->expenseNotes = (string) $e->notes;
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
            PartnershipExpense::find($this->editingId)?->update($payload);
        } else {
            PartnershipExpense::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Despesa salva.');
    }

    public function delete(int $id): void
    {
        PartnershipExpense::find($id)?->delete();
        session()->flash('status', 'Despesa excluída.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'total_amount', 'description', 'category_id', 'bank_account_id', 'expenseNotes']);
        $this->expenseDate = now()->format('Y-m-d');
        $this->applied_percentage = (string) $this->partnership->participation_percentage;
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

<div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
    <x-fx.card class="lg:col-span-2">
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

        @if ($expenses->isEmpty())
            <div class="text-sm text-mono-600">Nenhuma despesa.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Descrição</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">%</th>
                        <th class="text-right">Proporcional</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($expenses as $e)
                        <tr>
                            <td>{{ $e->date->format('d/m/Y') }}</td>
                            <td>{{ $e->description }} @if ($e->category)<span class="text-xxs text-mono-600">({{ $e->category->name }})</span>@endif</td>
                            <td class="text-right">R$ {{ number_format((float) $e->total_amount, 2, ',', '.') }}</td>
                            <td class="text-right text-xxs">{{ number_format((float) $e->applied_percentage, 2, ',', '.') }}%</td>
                            <td class="text-right font-semibold text-system-down">R$ {{ number_format((float) $e->proportional_amount, 2, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="edit({{ $e->id }})">Editar</button>
                                <button class="fx-btn fx-btn--text fx-btn--sm" wire:click="delete({{ $e->id }})" wire:confirm="Excluir despesa? O lançamento no caixa também será removido.">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-fx.card>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">{{ $editingId ? 'Editar' : 'Nova' }} despesa</h3>
        <form wire:submit="save" class="flex flex-col gap-sm">
            <x-fx.input label="Data" type="date" wire:model="expenseDate" />
            <x-fx.input label="Descrição" wire:model="description" required />
            <div class="grid grid-cols-2 gap-xs">
                <x-fx.input label="Total" type="text" x-money wire:model.live="total_amount" />
                <x-fx.input label="% aplicado" type="number" step="0.001" min="0" max="100" wire:model.live="applied_percentage" />
            </div>
            <div class="text-xxs text-mono-600">
                Proporcional para Dr. Cássio: <strong>R$ {{ number_format($this->proportionalPreview(), 2, ',', '.') }}</strong>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Categoria</label>
                <select wire:model="category_id" class="fx-form-field">
                    <option value="">—</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Conta (registro do débito) <span class="text-system-down">*</span></label>
                <select wire:model="bank_account_id" class="fx-form-field" required>
                    <option value="">—</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
                @error('bank_account_id')
                    <div class="text-xxs text-system-down mt-xxxs">{{ $message }}</div>
                @enderror
            </div>
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Notas</label>
                <textarea wire:model="expenseNotes" class="fx-form-field" rows="2"></textarea>
            </div>
            <div class="flex gap-xs">
                <button type="submit" class="fx-btn fx-btn--primary">Salvar</button>
                @if ($editingId)
                    <button type="button" class="fx-btn fx-btn--text" wire:click="cancel">Cancelar</button>
                @endif
            </div>
        </form>
        <a href="{{ route('partnership.show', $partnership) }}" class="fx-btn fx-btn--text fx-btn--sm mt-sm">← Voltar</a>
    </x-fx.card>
</div>
