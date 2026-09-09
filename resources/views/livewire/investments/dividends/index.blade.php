<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetDividend;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;
    public string $from = '';
    public string $to = '';
    public string $assetFilter = '';
    public string $typeFilter = '';

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to', 'assetFilter', 'typeFilter'])) $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'assetFilter', 'typeFilter']);
        $this->resetPage();
    }

    public bool $showFormModal = false;
    public ?int $editingId = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public ?int $asset_id = null;
    public string $payment_date = '';
    public string $type = 'dividend';
    public string $unit_amount = '';
    public string $quantity = '';
    public ?int $bank_account_id = null;
    public string $divNotes = '';

    public function mount(): void
    {
        $this->payment_date = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'asset_id' => 'required|exists:assets,id',
            'payment_date' => 'required|date|before_or_equal:today',
            'type' => 'required|in:dividend,jcp,fii',
            'unit_amount' => 'required|numeric|min:0.000001',
            'quantity' => 'required|numeric|min:0.000001',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'divNotes' => 'nullable|string',
        ];
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $this->showFormModal = true;
        $d = AssetDividend::findOrFail($id);
        $this->editingId = $d->id;
        $this->asset_id = $d->asset_id;
        $this->payment_date = $d->payment_date->format('Y-m-d');
        $this->type = $d->type;
        $this->unit_amount = (string) $d->unit_amount;
        $this->quantity = (string) $d->quantity;
        $this->bank_account_id = $d->bank_account_id;
        $this->divNotes = (string) $d->notes;
    }

    public function save(): void
    {
        $data = $this->validate();
        $total = round((float) $data['unit_amount'] * (float) $data['quantity'], 2);
        $payload = [
            'asset_id' => $data['asset_id'],
            'payment_date' => $data['payment_date'],
            'type' => $data['type'],
            'unit_amount' => $data['unit_amount'],
            'quantity' => $data['quantity'],
            'total' => $total,
            'bank_account_id' => $data['bank_account_id'],
            'notes' => $data['divNotes'],
        ];

        if ($this->editingId) {
            AssetDividend::find($this->editingId)?->update($payload);
        } else {
            AssetDividend::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Provento salvo.');
    }

    public function delete(int $id): void
    {
        AssetDividend::find($id)?->delete();
        session()->flash('status', 'Provento excluído.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->showFormModal = false;
        $this->resetValidation();
        $this->reset(['editingId', 'asset_id', 'unit_amount', 'quantity', 'bank_account_id', 'divNotes']);
        $this->type = 'dividend';
        $this->payment_date = now()->format('Y-m-d');
    }

    public function with(): array
    {
        $q = AssetDividend::with('asset', 'bankAccount');
        if ($this->assetFilter) $q->where('asset_id', $this->assetFilter);
        if ($this->typeFilter) $q->where('type', $this->typeFilter);
        return [
            'dividends' => $q->when($this->from, fn ($q) => $q->whereDate('payment_date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('payment_date', '<=', $this->to))->orderByDesc('payment_date')->orderByDesc('id')->paginate(25),
            'assets' => Asset::orderBy('ticker')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Proventos</x-slot>

<div class="investment-area">
    <x-investments.subnav />
    @if (session('error'))<x-jr.alert variant="error">{{ session('error') }}</x-jr.alert>@endif
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-xl font-bold">Proventos</h2><p class="mt-1 text-sm text-mono-600">Histórico dos dividendos e rendimentos recebidos por ativo.</p></div><x-jr.button wire:click="create"><span class="material-icons-outlined text-[18px]">add</span>Novo provento</x-jr.button></div>
<x-jr.card>
<div class="mb-4 flex items-center justify-between"><h3 class="font-semibold">Filtros</h3><button class="text-sm text-primary-500" wire:click="clearFilters">Limpar filtros</button></div>
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
<x-jr.input label="De" type="date" wire:model.live="from" /><x-jr.input label="Até" type="date" wire:model.live="to" />
<div><label class="mb-2 block">Ativo</label><select wire:model.live="assetFilter"><option value="">Todos</option>@foreach ($assets as $a)<option value="{{ $a->id }}">{{ $a->ticker }}</option>@endforeach</select></div>
<div><label class="mb-2 block">Tipo</label><select wire:model.live="typeFilter"><option value="">Todos</option>@foreach (\App\Domains\Investments\Models\AssetDividend::TYPE_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
</div></x-jr.card>
    <x-jr.card>
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

        @if ($dividends->isEmpty())
            <x-fx.empty-state icon="▤" title="Nenhum registro encontrado" description="Ajuste os filtros ou registre sua primeira entrada de proventos." />
        @else
            <div class="overflow-x-auto"><table class="investment-table">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Ativo</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Qtd</th>
                        <th class="text-right">R$/un</th>
                        <th class="text-right">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dividends as $d)
                        <tr>
                            <td>{{ $d->payment_date->format('d/m/Y') }}</td>
                            <td class="font-semibold">{{ $d->asset?->ticker }}</td>
                            <td>{{ \App\Domains\Investments\Models\AssetDividend::TYPE_LABELS[$d->type] }}</td>
                            <td class="text-right">{{ number_format((float) $d->quantity, 6, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) $d->unit_amount, 6, ',', '.') }}</td>
                            <td class="text-right text-up font-semibold">R$ {{ number_format((float) $d->total, 2, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button class="investment-action" wire:click="edit({{ $d->id }})">Editar</button>
                                <button class="investment-action" wire:click="delete({{ $d->id }})" wire:confirm="Excluir provento?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="mt-sm">{{ $dividends->links() }}</div>
        @endif
    </x-jr.card>

    @if ($showFormModal)
    <x-investments.modal :title="$editingId ? 'Editar provento' : 'Novo provento'">
@if ($errors->any())<div class="md:col-span-2"><x-jr.alert variant="error"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-jr.alert></div>@endif
            <div>
                <label class="mb-2 block">Ativo</label>
                <select wire:model="asset_id"  required>
                    <option value="">—</option>
                    @foreach ($assets as $a)
                        <option value="{{ $a->id }}">{{ $a->ticker }}</option>
                    @endforeach
                </select>
            </div>
            <x-jr.input label="Data do pagamento" type="date" name="payment_date" icon="edit_note" wire:model="payment_date" />
            <div>
                <label class="mb-2 block">Tipo</label>
                <select wire:model="type" >
                    <option value="dividend">Dividendo</option>
                    <option value="jcp">JCP</option>
                    <option value="fii">Rendimento FII</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4 md:col-span-2">
                <x-jr.input label="Quantidade" type="number" step="0.000001" name="quantity" icon="numbers" wire:model.live.debounce.300ms="quantity" />
                <x-jr.input label="Valor por unidade" type="number" step="0.000001" name="unit_amount" icon="edit_note" wire:model.live.debounce.300ms="unit_amount" />
            </div>
            <div>
                <label class="mb-2 block">Conta de crédito</label>
                <select wire:model="bank_account_id" >
                    <option value="">—</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="mb-2 block">Observações</label>
                <textarea wire:model="divNotes"  rows="2"></textarea>
            </div>
<div class="md:col-span-2 flex items-center justify-between border-t border-mono-100 pt-4"><span class="font-medium">Total recebido</span><strong class="text-xl">R$ {{ number_format((float)$quantity * (float)$unit_amount, 2, ',', '.') }}</strong></div>
<div class="md:col-span-2 rounded-2xl bg-primary-100 p-4 text-sm text-mono-900">Informe os valores efetivamente recebidos. Ao escolher uma conta, o recebimento também será registrado no Financeiro.</div>
    </x-investments.modal>
    @endif
</div>
