<?php

use App\Domains\Broadcasts\Models\TransmissionSuppression;
use Livewire\Volt\Component;

new class extends Component {
    public const PER_PAGE = 20;

    public string $phone = '';
    public string $name = '';
    public string $reason = '';
    public string $search = '';
    public int $page = 1;
    public ?string $notice = null;

    public function updatedSearch(): void { $this->page = 1; }
    public function pageTo(int $p): void { $this->page = max(1, $p); }

    public function add(): void
    {
        $this->validate(['phone' => 'required|string|max:40', 'name' => 'nullable|string|max:120', 'reason' => 'nullable|string|max:300']);
        $row = TransmissionSuppression::suppress($this->phone, trim($this->name), trim($this->reason), auth()->id());
        if (! $row) {
            $this->addError('phone', 'Telefone inválido. Use DDD + número (ex.: 85 99999-0000).');

            return;
        }
        $this->notice = 'Telefone final '.substr(preg_replace('/\D/', '', $row->phone), -4).' não receberá mais transmissões.';
        $this->reset('phone', 'name', 'reason');
    }

    public function restore(int $id): void
    {
        $row = TransmissionSuppression::active()->findOrFail($id);
        $row->restore(auth()->id());
        $this->notice = 'Telefone final '.substr(preg_replace('/\D/', '', $row->phone), -4).' voltou a receber transmissões.';
    }

    public function with(): array
    {
        $q = TransmissionSuppression::active()->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'));
        $total = (clone $q)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $this->page = min($this->page, $pages);

        return ['rows' => $q->orderByDesc('suppressed_at')->orderByDesc('id')->forPage($this->page, self::PER_PAGE)->get(), 'total' => $total, 'pages' => $pages];
    }
}; ?>

<div class="flex flex-col gap-md" data-suppressions>
    <x-fx.alert variant="info">Quem está nesta lista não recebe nenhuma transmissão, em qualquer etiqueta, de qualquer usuário do sistema. Por enquanto a inclusão é manual (ex.: a pessoa respondeu SAIR); o sistema ainda não lê respostas automaticamente.</x-fx.alert>
    @if ($notice)<x-fx.alert variant="success">{{ $notice }}</x-fx.alert>@endif
    <x-fx.card>
        <h3 class="font-semibold text-mono-900">Adicionar à lista "Não receber"</h3>
        <form wire:submit="add" class="mt-3 grid gap-3 md:grid-cols-3">
            <x-jr.input label="Telefone *" name="phone" wire:model="phone" x-phone inputmode="tel" />
            <x-jr.input label="Nome (opcional)" name="name" wire:model="name" maxlength="120" />
            <x-jr.input label="Motivo (opcional)" name="reason" wire:model="reason" maxlength="300" placeholder="Ex.: respondeu SAIR" />
            <div class="md:col-span-3 flex justify-end"><button type="submit" class="h-10 rounded-xl bg-primary-500 px-4 text-sm font-semibold text-white">Adicionar</button></div>
        </form>
    </x-fx.card>
    <x-fx.card>
        <div class="flex flex-wrap items-end justify-between gap-2">
            <h3 class="font-semibold text-mono-900">Não receber ({{ $total }})</h3>
            <div class="w-full md:w-64"><x-jr.input label="Buscar pelo nome" name="search" wire:model.live.debounce.400ms="search" /></div>
        </div>
        <ul class="mt-3 divide-y divide-mono-100 text-sm">
            @forelse ($rows as $r)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="sup-{{ $r->id }}">
                    <span>{{ $r->name ?: 'Sem nome' }} <span class="text-xs text-mono-500">{{ $r->maskedPhone() }}</span>
                        <span class="block text-xs text-mono-600">{{ $r->suppressed_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · {{ \App\Domains\Broadcasts\Models\TransmissionSuppression::SOURCES[$r->source] ?? $r->source }}@if ($r->reason) · {{ $r->reason }}@endif</span></span>
                    <button type="button" wire:click="restore({{ $r->id }})" wire:confirm="Esta pessoa voltará a receber transmissões. Confirmar?" class="h-9 rounded-xl border border-mono-200 px-3 text-xs">Voltar a receber</button>
                </li>
            @empty
                <li class="py-2 text-mono-500">Ninguém na lista.</li>
            @endforelse
        </ul>
        @if ($pages > 1)
            <div class="mt-2 flex items-center justify-between text-xs">
                <button type="button" wire:click="pageTo({{ $page - 1 }})" @disabled($page <= 1) class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50">Anterior</button>
                <span>Página {{ $page }} de {{ $pages }}</span>
                <button type="button" wire:click="pageTo({{ $page + 1 }})" @disabled($page >= $pages) class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50">Próxima</button>
            </div>
        @endif
    </x-fx.card>
</div>
