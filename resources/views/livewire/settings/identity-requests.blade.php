<?php

use App\Domains\Contacts\Models\IdentityChangeRequest;
use App\Domains\Contacts\Services\PersonIdentityService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/** Pendências de cadastro: troca de CPF e exclusão de e-mail pedidas pelas pessoas, para o admin aprovar ou recusar. */
new #[Layout('layouts.app')] class extends Component {
    #[Url] public string $show = 'pending';

    public ?int $rejectingId = null;

    public string $rejectNote = '';

    public string $message = '';

    public string $errorMessage = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function approve(int $id, PersonIdentityService $service): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->resetMessages();
        try {
            $service->approve(IdentityChangeRequest::findOrFail($id), auth()->user());
            $this->message = 'Pedido aprovado. A alteração já vale em todos os módulos.';
        } catch (ValidationException $e) {
            $this->errorMessage = collect($e->errors())->flatten()->first();
        }
    }

    public function startReject(int $id): void
    {
        $this->resetMessages();
        $this->rejectingId = $id;
        $this->rejectNote = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectNote = '';
    }

    public function confirmReject(PersonIdentityService $service): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->validate(['rejectNote' => 'nullable|string|max:500']);
        try {
            $service->reject(IdentityChangeRequest::findOrFail($this->rejectingId), auth()->user(), $this->rejectNote);
            $this->message = 'Pedido recusado. Nada foi alterado no cadastro.';
        } catch (ValidationException $e) {
            $this->errorMessage = collect($e->errors())->flatten()->first();
        }
        $this->cancelReject();
    }

    private function resetMessages(): void
    {
        $this->message = '';
        $this->errorMessage = '';
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $q = IdentityChangeRequest::with(['contact', 'requester', 'decider'])->orderByDesc('id');
        $q = $this->show === 'pending' ? $q->pending() : $q->where('status', '!=', 'pending')->limit(100);

        return [
            'requests' => $q->get(),
            'pendingCount' => PersonIdentityService::pendingCount(),
        ];
    }
}; ?>

<x-slot name="header">Pendências de cadastro</x-slot>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-4" data-identity-requests>
    <div>
        <h2 class="text-2xl font-bold tracking-tight">Pendências de cadastro</h2>
        <p class="mt-1 text-sm text-mono-600">Pedidos de troca de CPF e de exclusão de e-mail. Nada muda no cadastro até você aprovar.</p>
    </div>

    @if ($message)<x-jr.alert variant="success">{{ $message }}</x-jr.alert>@endif
    @if ($errorMessage)<x-jr.alert variant="error">{{ $errorMessage }}</x-jr.alert>@endif

    <div class="flex gap-2">
        <button type="button" wire:click="$set('show', 'pending')" class="h-10 rounded-pill px-4 text-sm font-semibold {{ $show === 'pending' ? 'bg-primary-500 text-white' : 'bg-mono-100 text-mono-900' }}">Aguardando ({{ $pendingCount }})</button>
        <button type="button" wire:click="$set('show', 'done')" class="h-10 rounded-pill px-4 text-sm font-semibold {{ $show !== 'pending' ? 'bg-primary-500 text-white' : 'bg-mono-100 text-mono-900' }}">Decididos</button>
    </div>

    @forelse ($requests as $req)
        <x-jr.card wire:key="idreq-{{ $req->id }}" data-identity-request="{{ $req->id }}">
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-primary-500">{{ $req->typeLabel() }}</p>
                        <p class="mt-1 text-base font-bold text-mono-900">
                            @if ($req->contact && ! $req->contact->trashed())
                                <a href="{{ route('contacts.show', $req->contact_id) }}" class="hover:text-primary-500" wire:navigate>{{ $req->contact->name }}</a>
                            @else
                                {{ $req->contact?->name ?? 'Contato removido' }}
                            @endif
                        </p>
                        <p class="text-xs text-mono-600">Pedido por {{ $req->requester?->name ?? '—' }} em {{ $req->created_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }}</p>
                    </div>
                    <x-jr.badge :variant="$req->status === 'approved' ? 'success' : ($req->status === 'rejected' ? 'error' : 'neutral')">{{ $req->statusLabel() }}</x-jr.badge>
                </div>

                <div class="rounded-xl bg-mono-50 px-4 py-3 text-sm">
                    @if ($req->type === 'document')
                        <p><span class="text-mono-600">CPF atual:</span> <strong class="text-mono-900">{{ $req->old_value ?: '—' }}</strong></p>
                        <p class="mt-1"><span class="text-mono-600">CPF pedido:</span> <strong class="text-mono-900">{{ $req->new_value }}</strong></p>
                    @else
                        <p><span class="text-mono-600">Excluir o e-mail:</span> <strong class="break-all text-mono-900">{{ $req->old_value }}</strong></p>
                        @if ($req->contact)
                            @php $others = array_values(array_diff(app(\App\Domains\Contacts\Services\PersonIdentityService::class)->emailsOf($req->contact), [$req->old_value])); @endphp
                            <p class="mt-1 text-mono-600">Fica(m): {{ $others ? implode(', ', $others) : 'nenhum e-mail' }}</p>
                        @endif
                    @endif
                    @if ($req->decided_at)
                        <p class="mt-2 text-xs text-mono-600">{{ $req->statusLabel() }} por {{ $req->decider?->name ?? '—' }} em {{ $req->decided_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }}{{ $req->decision_note ? ' · '.$req->decision_note : '' }}</p>
                    @endif
                </div>

                @if ($req->isPending())
                    @if ($rejectingId === $req->id)
                        <div class="flex flex-col gap-2">
                            <label for="reject-note-{{ $req->id }}" class="text-sm font-medium text-mono-600">Motivo (opcional)</label>
                            <textarea id="reject-note-{{ $req->id }}" wire:model="rejectNote" rows="2" class="w-full rounded-xl border border-mono-200 px-4 py-2 text-sm"></textarea>
                            <div class="grid grid-cols-2 gap-2">
                                <x-jr.button variant="mono" wire:click="cancelReject">Voltar</x-jr.button>
                                <x-jr.button variant="danger" wire:click="confirmReject" data-confirm-reject>Recusar</x-jr.button>
                            </div>
                        </div>
                    @else
                        <div class="grid grid-cols-2 gap-2">
                            <x-jr.button variant="standard" wire:click="startReject({{ $req->id }})" data-reject>Recusar</x-jr.button>
                            <x-jr.button wire:click="approve({{ $req->id }})" wire:confirm="Aprovar este pedido? A alteração vale na hora." data-approve>Aprovar</x-jr.button>
                        </div>
                    @endif
                @endif
            </div>
        </x-jr.card>
    @empty
        <x-jr.card><p class="text-sm text-mono-600">{{ $show === 'pending' ? 'Nenhum pedido aguardando. Tudo em dia.' : 'Nenhum pedido decidido ainda.' }}</p></x-jr.card>
    @endforelse
</div>
