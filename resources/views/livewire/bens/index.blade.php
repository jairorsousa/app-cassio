<?php

use App\Domains\Assets\Models\Property;
use App\Domains\Banking\Models\BankAccount;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $kind = 'imovel';

    public string $description = '';

    public string $identifier = '';

    public string $acquired_at = '';

    public string $acquisition_value = '';

    public string $current_value = '';

    public string $current_value_date = '';

    public string $owner = '';

    public string $bank_account_id = '';

    public string $status = 'active';

    public string $sold_at = '';

    public string $sale_value = '';

    public string $notes = '';

    public $attachment = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Property::findOrFail($id);
        $this->resetForm();
        $this->editingId = $p->id;
        foreach (['kind', 'description', 'identifier', 'owner', 'status', 'notes'] as $f) {
            $this->{$f} = (string) $p->{$f};
        }
        foreach (['acquired_at', 'current_value_date', 'sold_at'] as $f) {
            $this->{$f} = $p->{$f}?->toDateString() ?? '';
        }
        foreach (['acquisition_value', 'current_value', 'sale_value'] as $f) {
            $this->{$f} = $p->{$f} === null ? '' : (string) $p->{$f};
        }
        $this->bank_account_id = (string) ($p->bank_account_id ?? '');
        $this->showForm = true;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'kind', 'description', 'identifier', 'acquired_at', 'acquisition_value', 'current_value', 'current_value_date', 'owner', 'bank_account_id', 'status', 'sold_at', 'sale_value', 'notes', 'attachment']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate([
            'kind' => ['required', Rule::in(array_keys(Property::KINDS))],
            'description' => ['required', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'acquired_at' => ['nullable', 'date'],
            'acquisition_value' => ['nullable', 'numeric', 'min:0'],
            'current_value' => ['nullable', 'numeric', 'min:0'],
            'current_value_date' => ['nullable', 'date'],
            'owner' => ['nullable', Rule::in(array_keys(Property::OWNERS))],
            'bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'status' => ['required', Rule::in(['active', 'sold'])],
            'sold_at' => ['nullable', 'required_if:status,sold', 'date'],
            'sale_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        unset($data['attachment']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $property = $this->editingId ? Property::findOrFail($this->editingId) : new Property;
        $property->fill($data)->save();
        if ($this->attachment) {
            if ($property->attachment_path) {
                Storage::disk('local')->delete($property->attachment_path);
            }
            $path = $this->attachment->storeAs('bens/'.$property->id, Str::uuid().'.'.$this->attachment->getClientOriginalExtension(), 'local');
            $property->update(['attachment_path' => $path, 'attachment_original_name' => $this->attachment->getClientOriginalName(), 'attachment_mime' => $this->attachment->getMimeType(), 'attachment_size' => $this->attachment->getSize()]);
        }
        app(\App\Domains\Dashboard\Services\DashboardSnapshotService::class)->refresh();
        $this->showForm = false;
        session()->flash('status', $this->editingId ? 'Bem atualizado.' : 'Bem cadastrado.');
        $this->resetForm();
    }

    public function removeAttachment(int $id): void
    {
        $p = Property::findOrFail($id);
        if ($p->attachment_path) {
            Storage::disk('local')->delete($p->attachment_path);
        }
        $p->update(['attachment_path' => null, 'attachment_original_name' => null, 'attachment_mime' => null, 'attachment_size' => null]);
    }

    public function delete(int $id): void
    {
        Property::findOrFail($id)->delete();
        app(\App\Domains\Dashboard\Services\DashboardSnapshotService::class)->refresh();
        session()->flash('status', 'Bem excluído.');
    }

    public function with(): array
    {
        return [
            'properties' => Property::with('bankAccount')->orderBy('status')->orderBy('description')->get(),
            'total' => Property::activeTotal(),
            'accounts' => BankAccount::orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<x-slot name="header">Investimentos</x-slot>

@php $m = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 2, ',', '.'); $in = 'h-10 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2 w-full'; @endphp

<div class="flex flex-col gap-space-5">
    <x-investments.subnav />
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
    <x-fx.card>
        <div class="flex flex-wrap items-center gap-space-3">
            <div>
                <h3 class="text-fs-16 font-semibold text-cryptex-text-primary">Bens</h3>
                <div class="text-fs-12 text-cryptex-text-secondary">Imóveis, veículos e outros bens. Cadastro patrimonial: não cria lançamento e não entra no resultado.</div>
            </div>
            <div class="ml-auto text-right"><div class="text-fs-12 text-cryptex-text-tertiary uppercase">Total em bens ativos</div><div class="text-fs-20 font-bold font-mono">{{ $m($total) }}</div></div>
            <button type="button" wire:click="create" class="min-h-[40px] px-3 rounded-sm bg-cryptex-brand-400 text-white font-semibold">Novo bem</button>
        </div>
        @if ($properties->isEmpty())
            <div class="py-10 text-center text-sm text-mono-600" data-empty="bens">Nenhum bem cadastrado.</div>
        @else
        <div class="mt-space-3 space-y-3 md:hidden" data-mobile="bens">
            @foreach ($properties as $p)
                <div class="rounded-sm border border-cryptex-border-default p-3 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0"><strong class="break-words">{{ $p->description }}</strong><div class="text-xs text-mono-600">{{ \App\Domains\Assets\Models\Property::KINDS[$p->kind] ?? $p->kind }}@if ($p->identifier) · {{ $p->identifier }}@endif</div></div>
                        <div class="text-right"><strong>{{ $m($p->status === 'sold' && $p->sale_value !== null ? $p->sale_value : $p->current_value) }}</strong><div class="text-xs {{ $p->status === 'sold' ? 'text-system-down' : 'text-mono-600' }}">{{ $p->status === 'sold' ? 'Vendido'.($p->sold_at ? ' em '.$p->sold_at->format('d/m/Y') : '') : 'Ativo' }}</div></div>
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                        @if ($p->owner)<dt class="text-mono-600">Titular</dt><dd class="text-right">{{ \App\Domains\Assets\Models\Property::OWNERS[$p->owner] ?? $p->owner }}</dd>@endif
                        @if ($p->bankAccount)<dt class="text-mono-600">Conta</dt><dd class="text-right">{{ $p->bankAccount->name }}</dd>@endif
                        <dt class="text-mono-600">Valor atual</dt><dd class="text-right">{{ $m($p->current_value) }}@if ($p->current_value_date) · {{ $p->current_value_date->format('d/m/Y') }}@endif</dd>
                        <dt class="text-mono-600">Aquisição</dt><dd class="text-right">{{ $m($p->acquisition_value) }}@if ($p->acquired_at) · {{ $p->acquired_at->format('d/m/Y') }}@endif</dd>
                    </dl>
                    <div class="mt-2 flex gap-4 text-xs">
                        @if ($p->hasAttachment())<a class="text-cryptex-brand-400" href="{{ route('bens.attachment', $p) }}">Baixar anexo</a>@endif
                        <button type="button" wire:click="edit({{ $p->id }})" class="ml-auto text-cryptex-brand-400">Editar</button>
                        <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Excluir este bem?" class="text-system-down">Excluir</button>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-space-3 hidden overflow-x-auto md:block">
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Bem</th><th class="text-left">Tipo</th><th class="text-left">Titular</th><th class="text-right">Aquisição</th><th class="text-right">Valor atual</th><th class="text-left">Situação</th><th class="text-left">Anexo</th><th></th></tr></thead>
                <tbody>
                    @foreach ($properties as $p)
                        <tr>
                            <td><strong>{{ $p->description }}</strong>@if ($p->identifier)<div class="text-xs text-mono-600">{{ $p->identifier }}</div>@endif</td>
                            <td>{{ \App\Domains\Assets\Models\Property::KINDS[$p->kind] ?? $p->kind }}</td>
                            <td>{{ \App\Domains\Assets\Models\Property::OWNERS[$p->owner] ?? 'Não informado' }}</td>
                            <td class="text-right">{{ $m($p->acquisition_value) }}<div class="text-xs text-mono-600">{{ $p->acquired_at?->format('d/m/Y') }}</div></td>
                            <td class="text-right">{{ $m($p->current_value) }}<div class="text-xs text-mono-600">{{ $p->current_value_date?->format('d/m/Y') }}</div></td>
                            <td>{{ $p->status === 'sold' ? 'Vendido'.($p->sold_at ? ' em '.$p->sold_at->format('d/m/Y') : '') : 'Ativo' }}</td>
                            <td>@if ($p->hasAttachment())<a class="text-cryptex-brand-400" href="{{ route('bens.attachment', $p) }}">Baixar</a>@else — @endif</td>
                            <td class="text-right whitespace-nowrap"><button type="button" wire:click="edit({{ $p->id }})" class="text-cryptex-brand-400">Editar</button> <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Excluir este bem?" class="text-system-down ml-2">Excluir</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-fx.card>

    @if ($showForm)
        <x-fx.card>
            <h3 class="text-fs-16 font-semibold mb-space-3">{{ $editingId ? 'Editar bem' : 'Novo bem' }}</h3>
            <form wire:submit="save" class="grid gap-space-3 md:grid-cols-3 text-sm">
                <label class="flex flex-col gap-1">Tipo<select wire:model="kind" class="{{ $in }}">@foreach (\App\Domains\Assets\Models\Property::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="flex flex-col gap-1 md:col-span-2">Descrição<input wire:model="description" class="{{ $in }}" />@error('description')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <label class="flex flex-col gap-1">Identificação (matrícula, placa)<input wire:model="identifier" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Titular<select wire:model="owner" class="{{ $in }}"><option value="">Não informado</option>@foreach (\App\Domains\Assets\Models\Property::OWNERS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="flex flex-col gap-1">Conta vinculada (opcional)<select wire:model="bank_account_id" class="{{ $in }}"><option value="">Nenhuma</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
                <label class="flex flex-col gap-1">Data de aquisição<input type="date" wire:model="acquired_at" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Valor de aquisição<input inputmode="decimal" wire:model="acquisition_value" class="{{ $in }}" placeholder="0.00" />@error('acquisition_value')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <span></span>
                <label class="flex flex-col gap-1">Valor atual<input inputmode="decimal" wire:model="current_value" class="{{ $in }}" placeholder="0.00" />@error('current_value')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <label class="flex flex-col gap-1">Data do valor atual<input type="date" wire:model="current_value_date" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Situação<select wire:model.live="status" class="{{ $in }}"><option value="active">Ativo</option><option value="sold">Vendido</option></select></label>
                @if ($status === 'sold')
                    <label class="flex flex-col gap-1">Data da venda<input type="date" wire:model="sold_at" class="{{ $in }}" />@error('sold_at')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                    <label class="flex flex-col gap-1">Valor da venda<input inputmode="decimal" wire:model="sale_value" class="{{ $in }}" /></label>
                    <span></span>
                @endif
                <label class="flex flex-col gap-1 md:col-span-2">Observações<textarea wire:model="notes" rows="2" class="rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2 py-1"></textarea></label>
                <label class="flex flex-col gap-1">Anexo (PDF, JPG, PNG até 10 MB — privado)<input type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png" />@error('attachment')<span class="text-system-down text-xs">{{ $message }}</span>@enderror</label>
                <div class="md:col-span-3 flex gap-space-3">
                    <button type="submit" class="min-h-[40px] px-3 rounded-sm bg-cryptex-brand-400 text-white font-semibold">Salvar</button>
                    <button type="button" wire:click="$set('showForm', false)" class="min-h-[40px] px-3 rounded-sm border border-cryptex-border-default">Cancelar</button>
                    @if ($editingId && \App\Domains\Assets\Models\Property::find($editingId)?->hasAttachment())
                        <button type="button" wire:click="removeAttachment({{ $editingId }})" class="min-h-[40px] px-3 text-system-down">Remover anexo</button>
                    @endif
                </div>
            </form>
        </x-fx.card>
    @endif
</div>
