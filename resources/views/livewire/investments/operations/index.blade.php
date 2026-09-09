<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetOperation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $assetFilter = '';
    #[Url]
    public string $typeFilter = '';

    public string $from = '';
    public string $to = '';

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
    public string $opDate = '';
    public string $opType = 'buy';
    public string $quantity = '';
    public string $unit_price = '';
    public string $fees = '0';
    public ?int $bank_account_id = null;
    public string $opNotes = '';

    public function mount(): void
    {
        $this->opDate = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'asset_id' => 'required|exists:assets,id',
            'opDate' => 'required|date|before_or_equal:today',
            'opType' => 'required|in:buy,sell',
            'quantity' => 'required|numeric|min:0.000001',
            'unit_price' => 'required|numeric|min:0',
            'fees' => 'required|numeric|min:0',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'opNotes' => 'nullable|string',
        ];
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $this->showFormModal = true;
        $op = AssetOperation::findOrFail($id);
        $this->editingId = $op->id;
        $this->asset_id = $op->asset_id;
        $this->opDate = $op->date->format('Y-m-d');
        $this->opType = $op->type;
        $this->quantity = (string) $op->quantity;
        $this->unit_price = (string) $op->unit_price;
        $this->fees = (string) $op->fees;
        $this->bank_account_id = $op->bank_account_id;
        $this->opNotes = (string) $op->notes;
    }

    public function save(): void
    {
        $data = $this->validate();
        $qty = (float) $data['quantity'];
        $unit = (float) $data['unit_price'];
        $fees = (float) $data['fees'];
        $total = round($qty * $unit + ($data['opType'] === 'buy' ? $fees : -$fees), 2);

        $payload = [
            'asset_id' => $data['asset_id'],
            'date' => $data['opDate'],
            'type' => $data['opType'],
            'quantity' => $qty,
            'unit_price' => $unit,
            'fees' => $fees,
            'total' => $total,
            'bank_account_id' => $data['bank_account_id'],
            'notes' => $data['opNotes'],
        ];

        app(\App\Domains\Investments\Services\InvestmentLedgerService::class)->saveOperation($payload, $this->editingId);

        $this->resetForm();
        session()->flash('status', 'Operação salva.');
    }

    public function delete(int $id): void
    {
        try {
            app(\App\Domains\Investments\Services\InvestmentLedgerService::class)->deleteOperation($id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first());
            return;
        }
        session()->flash('status', 'Operação excluída.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->showFormModal = false;
        $this->resetValidation();
        $this->reset(['editingId', 'asset_id', 'quantity', 'unit_price', 'fees', 'bank_account_id', 'opNotes']);
        $this->opType = 'buy';
        $this->fees = '0';
        $this->opDate = now()->format('Y-m-d');
    }

    public function with(): array
    {
        $q = AssetOperation::with('asset', 'bankAccount');
        if ($this->assetFilter) $q->where('asset_id', $this->assetFilter);
        if ($this->typeFilter) $q->where('type', $this->typeFilter);

        return [
            'operations' => $q->when($this->from, fn ($q) => $q->whereDate('date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('date', '<=', $this->to))->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'assets' => Asset::orderBy('ticker')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Operações</x-slot>

<div class="investment-area">
    <x-investments.subnav />
    @if (session('error'))<x-jr.alert variant="error">{{ session('error') }}</x-jr.alert>@endif
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-xl font-bold">Movimentações</h2><p class="mt-1 text-sm text-mono-600">Compras, aplicações, vendas e resgates com integração ao Financeiro.</p></div><x-jr.button wire:click="create"><span class="material-icons-outlined text-[18px]">add</span>Nova movimentação</x-jr.button></div>
<x-jr.card>
<div class="mb-4 flex items-center justify-between"><h3 class="font-semibold">Filtros</h3><button class="text-sm text-primary-500" wire:click="clearFilters">Limpar filtros</button></div>
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
<x-jr.input label="De" type="date" wire:model.live="from" /><x-jr.input label="Até" type="date" wire:model.live="to" />
<div><label class="mb-2 block">Ativo</label><select wire:model.live="assetFilter"><option value="">Todos</option>@foreach ($assets as $a)<option value="{{ $a->id }}">{{ $a->ticker }}</option>@endforeach</select></div>
<div><label class="mb-2 block">Tipo</label><select wire:model.live="typeFilter"><option value="">Todos</option><option value="buy">Compra / aplicação</option><option value="sell">Venda / resgate</option></select></div>
</div></x-jr.card>
    <x-jr.card>
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

        @if ($operations->isEmpty())
            <x-fx.empty-state icon="↔" title="Nenhum registro encontrado" description="Ajuste os filtros ou registre sua primeira movimentação." />
        @else
            <div class="overflow-x-auto"><table class="investment-table">
                <thead>
                    <tr>
                        <th class="text-left">Data</th>
                        <th class="text-left">Ativo</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Qtd</th>
                        <th class="text-right">Preço</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Resultado realizado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($operations as $op)
                        <tr>
                            <td>{{ $op->date->format('d/m/Y') }}</td>
                            <td class="font-semibold">{{ $op->asset?->ticker }}</td>
                            <td>
                                <span class="fx-badge fx-badge--{{ $op->type === 'buy' ? 'down' : 'up' }}">
                                    {{ $op->type === 'buy' ? 'Compra' : 'Venda' }}
                                </span>
                            </td>
                            <td class="text-right">{{ number_format((float) $op->quantity, 6, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) $op->unit_price, 4, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) $op->total, 2, ',', '.') }}</td>
                            <td class="text-right {{ ((float) $op->realized_pnl) >= 0 ? 'text-up' : 'text-down' }}">
                                {{ $op->realized_pnl !== null ? 'R$ '.number_format((float) $op->realized_pnl, 2, ',', '.') : '—' }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button class="investment-action" wire:click="edit({{ $op->id }})">Editar</button>
                                <button class="investment-action" wire:click="delete({{ $op->id }})" wire:confirm="Excluir operação?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="mt-sm">{{ $operations->links() }}</div>
        @endif
    </x-jr.card>

    @if ($showFormModal)
    <x-investments.modal :title="$editingId ? 'Editar movimentação' : 'Nova movimentação'">
@if ($errors->any())<div class="md:col-span-2"><x-jr.alert variant="error"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-jr.alert></div>@endif
            <div>
                <label class="mb-2 block">Ativo</label>
                <select wire:model="asset_id"  required>
                    <option value="">—</option>
                    @foreach ($assets as $a)
                        <option value="{{ $a->id }}">{{ $a->ticker }} — {{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4 md:col-span-2">
                <x-jr.input label="Data" type="date" name="opDate" icon="event" wire:model="opDate" />
                <div>
                    <label class="mb-2 block">Tipo</label>
                    <select wire:model.live.debounce.300ms="opType" >
                        <option value="buy">Compra</option>
                        <option value="sell">Venda</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4 md:col-span-2">
                <x-jr.input label="Quantidade" type="number" step="0.000001" name="quantity" icon="numbers" wire:model.live.debounce.300ms="quantity" />
                <x-jr.input label="Preço unitário" type="number" step="0.0001" name="unit_price" icon="payments" wire:model.live.debounce.300ms="unit_price" />
            </div>
            <x-jr.input label="Taxas/corretagem" type="text" x-money name="fees" icon="edit_note" wire:model.live.debounce.300ms="fees" />
            <div>
                <label class="mb-2 block">Conta liquidação</label>
                <select wire:model="bank_account_id" >
                    <option value="">— nenhuma —</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="mb-2 block">Observações</label>
                <textarea wire:model="opNotes"  rows="2"></textarea>
            </div>
<div class="md:col-span-2 flex items-center justify-between border-t border-mono-100 pt-4"><span class="font-medium">Total da movimentação</span><strong class="text-xl">R$ {{ number_format(max(0, (float)$quantity * (float)$unit_price + ($opType === 'buy' ? (float)$fees : -(float)$fees)), 2, ',', '.') }}</strong></div>
<div class="md:col-span-2 rounded-2xl bg-primary-100 p-4 text-sm text-mono-900">Informe quantidade, preço unitário e taxas. Para aplicações controladas pelo valor total, utilize quantidade 1. Ao escolher uma conta, a movimentação também será lançada no Financeiro.</div>
    </x-investments.modal>
    @endif
</div>
