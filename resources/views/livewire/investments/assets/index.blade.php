<?php

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public bool $showFormModal = false;
    public ?int $editingId = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public string $ticker = '';
    public string $search = '';
    public string $classFilter = '';
    public string $newClass = '';
    public string $institution = '';
    public string $maturity_date = '';
    public string $liquidity = '';
    public string $name = '';
    public ?int $asset_class_id = null;
    public string $sector = '';
    public string $notes = '';
    public bool $assetStatus = true;

    public function rules(): array
    {
        return [
            'ticker' => ['required', 'string', 'max:20', \Illuminate\Validation\Rule::unique('assets', 'ticker')->ignore($this->editingId)],
            'name' => 'required|string|max:200',
            'asset_class_id' => 'nullable|required_without:newClass|exists:asset_classes,id',
            'newClass' => 'nullable|string|max:100',
            'institution' => 'nullable|string|max:120',
            'maturity_date' => 'nullable|date',
            'liquidity' => 'nullable|string|max:120',
            'sector' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
            'assetStatus' => 'boolean',
        ];
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $this->showFormModal = true;
        $a = Asset::findOrFail($id);
        $this->editingId = $a->id;
        $this->ticker = $a->ticker;
        $this->name = $a->name;
        $this->asset_class_id = $a->asset_class_id;
        $this->sector = (string) $a->sector;
        $this->notes = (string) $a->notes;
        $this->assetStatus = (bool) $a->status;
        $this->institution = (string) $a->institution;
        $this->maturity_date = $a->maturity_date?->format('Y-m-d') ?? '';
        $this->liquidity = (string) $a->liquidity;
    }

    public function save(): void
    {
        $this->ticker = strtoupper(trim($this->ticker));
        $this->newClass = trim($this->newClass);
        $data = $this->validate();
        if (trim($this->newClass) !== '') {
            $data['asset_class_id'] = AssetClass::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($this->newClass)], ['name' => trim($this->newClass), 'status' => true])->id;
        }
        $payload = [
            'ticker' => $data['ticker'],
            'name' => $data['name'],
            'asset_class_id' => $data['asset_class_id'],
            'sector' => $data['sector'],
            'notes' => $data['notes'],
            'status' => $data['assetStatus'],
            'institution' => $data['institution'] ?: null,
            'maturity_date' => $data['maturity_date'] ?: null,
            'liquidity' => $data['liquidity'] ?: null,
        ];

        if ($this->editingId) {
            Asset::find($this->editingId)?->update($payload);
        } else {
            Asset::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Ativo salvo.');
    }

    public function delete(int $id): void
    {
        $asset = Asset::findOrFail($id);
        if ($asset->operations()->exists() || $asset->dividends()->exists()) {
            session()->flash('error', 'Este ativo possui movimentações. Você pode desativá-lo no cadastro para preservar o histórico.');
            return;
        }
        $asset->delete();
        session()->flash('status', 'Ativo excluído.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->showFormModal = false;
        $this->resetValidation();
        $this->reset(['editingId', 'ticker', 'name', 'asset_class_id', 'sector', 'notes']);
        $this->assetStatus = true;
        $this->newClass = '';
        $this->reset(['institution', 'maturity_date', 'liquidity']);
    }

    public function with(): array
    {
        return [
            'assets' => Asset::with('assetClass', 'position')->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('ticker', 'like', '%'.$this->search.'%')->orWhere('name', 'like', '%'.$this->search.'%')))->when($this->classFilter, fn ($q) => $q->where('asset_class_id', $this->classFilter))->orderBy('ticker')->get(),
            'classes' => AssetClass::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Ativos</x-slot>

<div class="investment-area">
    <x-investments.subnav />
    @if (session('error'))<x-jr.alert variant="error">{{ session('error') }}</x-jr.alert>@endif
    <div class="flex flex-wrap items-center justify-between gap-4"><div><h2 class="text-xl font-bold">Ativos cadastrados</h2><p class="mt-1 text-sm text-mono-600">Organize e acompanhe seus investimentos.</p></div><x-jr.button wire:click="create"><span class="material-icons-outlined text-[18px]">add</span>Novo ativo</x-jr.button></div>
    <x-jr.card>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-jr.input label="Buscar ativo" icon="search" wire:model.live.debounce.300ms="search" placeholder="Código ou nome" />
            <div><label class="mb-2 block">Classe</label><select wire:model.live="classFilter"><option value="">Todas</option>@foreach ($classes as $class)<option value="{{ $class->id }}">{{ $class->name }}</option>@endforeach</select></div>
        </div>
    </x-jr.card>
    <x-jr.card>
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
        @if ($assets->isEmpty())
            <div class="text-sm text-mono-600">Nenhum ativo cadastrado.</div>
        @else
            <div class="overflow-x-auto"><table class="investment-table">
                <thead>
                    <tr>
                        <th class="text-left">Ticker</th>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Classe</th>
                        <th class="text-right">Quantidade</th>
                        <th class="text-right">Preço médio</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $a)
                        <tr>
                            <td class="font-semibold">{{ $a->ticker }}</td>
                            <td>{{ $a->name }} @unless($a->status)<span class="text-xxs text-mono-600">(inativo)</span>@endunless</td>
                            <td>{{ $a->assetClass?->name }}<p class="mt-1 text-xs text-mono-600">{{ $a->institution }} @if($a->maturity_date) · Vence {{ $a->maturity_date->format('d/m/Y') }} @endif</p></td>
                            <td class="text-right">{{ number_format((float) ($a->position?->quantity ?? 0), 6, ',', '.') }}</td>
                            <td class="text-right">R$ {{ number_format((float) ($a->position?->average_price ?? 0), 4, ',', '.') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button class="investment-action" wire:click="edit({{ $a->id }})">Editar</button>
                                <button class="investment-action" wire:click="delete({{ $a->id }})" wire:confirm="Excluir ativo?">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </x-jr.card>

    @if ($showFormModal)
    <x-investments.modal :title="$editingId ? 'Editar registro' : 'Novo ativo'">
@if ($errors->any())<div class="md:col-span-2"><x-jr.alert variant="error"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-jr.alert></div>@endif
            <div class="md:col-span-2 border-b border-mono-100 pb-3 font-bold"><span class="material-icons-outlined align-middle text-primary-500">category</span> Identificação do investimento</div>
            <x-jr.input label="Código / ticker *" name="ticker" icon="tag" wire:model="ticker" placeholder="PETR4, CDB-BANCO-2027..." required />
            <x-jr.input label="Nome" name="name" icon="edit_note" wire:model="name" required />
            <div>
                <label class="mb-2 block">Classe</label>
                <select wire:model="asset_class_id">
                    <option value="">— selecionar —</option>
                    @foreach ($classes as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <x-jr.input label="Setor" name="sector" icon="edit_note" wire:model="sector" />
            <x-jr.input label="Ou cadastrar uma nova classe" name="newClass" wire:model="newClass" placeholder="Renda fixa, Fundos, Cripto..." helper="Preencha somente se a classe não estiver na lista." />
            <div class="md:col-span-2 border-b border-mono-100 pb-3 font-bold"><span class="material-icons-outlined align-middle text-primary-500">account_balance</span> Custódia e disponibilidade</div>
            <x-jr.input label="Instituição / corretora" name="institution" icon="account_balance" wire:model="institution" />
            <x-jr.input label="Vencimento (opcional)" name="maturity_date" icon="event" type="date" wire:model="maturity_date" />
            <x-jr.input label="Liquidez / prazo de resgate" name="liquidity" wire:model="liquidity" placeholder="Diária, D+2, no vencimento..." />
            <div class="md:col-span-2">
                <label class="mb-2 block">Observações</label>
                <textarea wire:model="notes"  rows="2"></textarea>
            </div>
            <label class="flex items-center gap-xs text-sm">
                <input type="checkbox" wire:model="assetStatus" class="h-5 w-5 rounded border-mono-300 text-primary-500 focus:ring-primary-500" /> Ativo
            </label>
    </x-investments.modal>
    @endif
</div>
