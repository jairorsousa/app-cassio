<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Partnership\Models\Partnership;
use App\Domains\Partnership\Models\PartnershipDistribution;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Partnership $partnership;

    public ?int $editingId = null;
    public bool $showFormModal = false;
    public string $distDate = '';
    public string $amount = '';
    public ?int $bank_account_id = null;
    public string $source = '';
    public string $distNotes = '';

    public function mount(Partnership $partnership): void
    {
        $this->partnership = $partnership;
        $this->distDate = now()->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'distDate' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'source' => 'nullable|string|max:200',
            'distNotes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Informe a conta de destino para a distribuição entrar no caixa.',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $d = $this->partnership->distributions()->findOrFail($id);
        $this->editingId = $d->id;
        $this->distDate = $d->date->format('Y-m-d');
        $this->amount = (string) $d->amount;
        $this->bank_account_id = $d->bank_account_id;
        $this->source = (string) $d->source;
        $this->distNotes = (string) $d->notes;
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();
        $payload = [
            'partnership_id' => $this->partnership->id,
            'date' => $data['distDate'],
            'amount' => $data['amount'],
            'bank_account_id' => $data['bank_account_id'],
            'source' => $data['source'],
            'notes' => $data['distNotes'],
        ];

        if ($this->editingId) {
            $this->partnership->distributions()->find($this->editingId)?->update($payload);
        } else {
            PartnershipDistribution::create($payload);
        }

        $this->resetForm();
        session()->flash('status', 'Distribuição salva.');
    }

    public function delete(int $id): void
    {
        $this->partnership->distributions()->find($id)?->delete();
        session()->flash('status', 'Distribuição excluída.');
    }

    public function cancel(): void { $this->resetForm(); }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'showFormModal', 'amount', 'bank_account_id', 'source', 'distNotes']);
        $this->distDate = now()->format('Y-m-d');
        $this->resetValidation();
    }

    public function with(): array
    {
        return [
            'distributions' => $this->partnership->distributions()->with('bankAccount')->orderByDesc('date')->get(),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">{{ $partnership->name }} · Distribuições</x-slot>

<div class="flex flex-col gap-md">
    <x-partnership.subnav :partnership="$partnership" />

    <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-mono-900">Distribuições</h2>
            <p class="mt-1 text-sm text-mono-600">Consulte retiradas e resultados recebidos da sociedade.</p>
        </div>
        <button type="button" class="fx-btn fx-btn--primary self-start sm:self-auto" wire:click="create">
            <span class="material-icons-outlined text-[18px]">add</span>
            Nova distribuição
        </button>
    </div>

    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

    @if ($distributions->isEmpty())
        <x-jr.empty-state icon="paid" title="Nenhuma distribuição cadastrada" description="Registre retiradas e distribuições recebidas desta sociedade." />
    @else
        <x-fx.card class="p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="fx-table text-sm">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Origem</th>
                            <th>Conta de destino</th>
                            <th class="text-right">Valor recebido</th>
                            <th class="w-20"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($distributions as $d)
                            <tr>
                                <td class="whitespace-nowrap font-mono">{{ $d->date->format('d/m/Y') }}</td>
                                <td class="font-medium">{{ $d->source ?: '—' }}</td>
                                <td>{{ $d->bankAccount?->name ?? '—' }}</td>
                                <td class="text-right whitespace-nowrap font-mono font-semibold text-system-up">R$ {{ number_format((float) $d->amount, 2, ',', '.') }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex justify-end gap-xxs">
                                        <button type="button" class="fx-btn fx-btn--icon h-9 w-9" wire:click="edit({{ $d->id }})" title="Editar distribuição" aria-label="Editar distribuição">
                                            <span class="material-icons-outlined text-[18px]">edit</span>
                                        </button>
                                        <button type="button" class="fx-btn fx-btn--icon h-9 w-9 text-error" wire:click="delete({{ $d->id }})" wire:confirm="Excluir distribuição? O lançamento no caixa também será removido." title="Excluir distribuição" aria-label="Excluir distribuição">
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
                        <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar distribuição' : 'Nova distribuição' }}</h3>
                        <p class="text-xs text-mono-600">{{ $partnership->name }}</p>
                    </div>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 overflow-y-auto px-6 py-5">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-jr.input label="Data" icon="event" type="date" name="distDate" wire:model="distDate" />
                            <x-jr.input label="Valor recebido" icon="attach_money" type="text" name="amount" x-money wire:model="amount" />

                            <div>
                                <label class="mb-2 block text-sm font-medium text-mono-600">Conta de destino <span class="text-error">*</span></label>
                                <select wire:model="bank_account_id" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm text-mono-900 focus:border-primary-500 focus:ring-0" required>
                                    <option value="">Selecione</option>
                                    @foreach ($accounts as $a)
                                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                                    @endforeach
                                </select>
                                @error('bank_account_id')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>

                            <x-jr.input label="Origem" helper="Ex.: lucro do trimestre" icon="source" name="source" wire:model="source" />

                            <div class="md:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-mono-600">Notas</label>
                                <textarea wire:model="distNotes" class="min-h-24 w-full rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm text-mono-900 focus:border-primary-500 focus:ring-0" rows="3"></textarea>
                                @error('distNotes')<p class="mt-2 text-xs font-medium text-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="fx-btn fx-btn--standard" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="fx-btn fx-btn--primary">Salvar distribuição</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
