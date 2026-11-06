<?php

use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Access;
use App\Support\UserAccessService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $inviteName = '';
    public string $inviteEmail = '';
    public array $inviteModules = [];
    /** Link mostrado só logo após gerar/regenerar (não fica salvo). */
    public ?string $generatedLink = null;
    public ?string $generatedFor = null;
    public array $editModules = [];
    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->loadEdit();
    }

    private function loadEdit(): void
    {
        $this->editModules = User::orderBy('id')->get()->mapWithKeys(fn ($u) => [$u->id => Access::clean((array) $u->modules)])->all();
    }

    private function guarded(callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $k => $msgs) {
                $this->addError($k, $msgs[0]);
            }
        }
    }

    public function invite(): void
    {
        $this->resetErrorBag();
        $this->guarded(function () {
            [$inv, $token] = UserAccessService::invite(auth()->user(), $this->inviteName, $this->inviteEmail, $this->inviteModules);
            $this->generatedLink = route('invite.accept', ['token' => $token]);
            $this->generatedFor = $inv->name;
            $this->notice = 'Convite criado. Copie o link abaixo e envie você mesmo (o sistema não envia e-mail). Ele vale por 7 dias e só pode ser usado uma vez.';
            $this->reset('inviteName', 'inviteEmail', 'inviteModules');
        });
    }

    public function regenerate(int $id): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($id) {
            $token = UserAccessService::regenerate(auth()->user(), $id);
            $this->generatedLink = route('invite.accept', ['token' => $token]);
            $this->generatedFor = UserInvitation::find($id)?->name;
            $this->notice = 'Novo link gerado. O link anterior deixou de funcionar.';
        });
    }

    public function revoke(int $id): void
    {
        UserAccessService::revoke(auth()->user(), $id);
        $this->generatedLink = null;
        $this->notice = 'Convite revogado.';
    }

    public function saveModules(int $userId): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($userId) {
            UserAccessService::setModules(auth()->user(), $userId, (array) ($this->editModules[$userId] ?? []));
            $this->notice = 'Módulos atualizados. Valem a partir da próxima ação do usuário.';
        });
        $this->loadEdit();
    }

    public function block(int $userId): void
    {
        $this->guarded(function () use ($userId) {
            UserAccessService::block(auth()->user(), $userId);
            $this->notice = 'Usuário bloqueado. Ele sai do sistema na próxima ação.';
        });
    }

    public function reactivate(int $userId): void
    {
        $this->guarded(function () use ($userId) {
            UserAccessService::reactivate(auth()->user(), $userId);
            $this->notice = 'Usuário reativado com os mesmos módulos de antes.';
        });
    }

    public function with(): array
    {
        return [
            'users' => User::orderBy('id')->get(['id', 'name', 'email', 'is_admin', 'status', 'modules']),
            'invitations' => UserInvitation::orderByDesc('id')->limit(50)->get(),
            'moduleLabels' => Access::MODULES,
        ];
    }
}; ?>

<x-slot name="header">Usuários e acessos</x-slot>

<div class="mx-auto max-w-4xl space-y-6" data-users-page>
    <div>
        <a href="{{ route('settings') }}" class="text-sm text-primary-600">← Configurações</a>
        <h2 class="mt-2 text-2xl font-bold tracking-tight">Usuários e acessos</h2>
        <p class="mt-2 text-sm text-mono-600">Convide pessoas e escolha quais módulos cada uma pode usar. O sistema não envia e-mail: copie o link e envie você mesmo. Só o e-mail convidado consegue criar a conta.</p>
    </div>

    @if ($notice)<x-jr.alert variant="success">{{ $notice }}</x-jr.alert>@endif
    @error('users')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('invite')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror

    @if ($generatedLink)
        <x-jr.card data-generated-link>
            <h3 class="font-bold">Link de convite{{ $generatedFor ? ' para '.$generatedFor : '' }}</h3>
            <p class="mt-1 text-xs text-mono-600">Este link aparece só agora. Se perder, gere um novo.</p>
            <div class="mt-2 flex flex-wrap gap-2" x-data="{ copied: false }">
                <input type="text" readonly value="{{ $generatedLink }}" class="h-10 min-w-0 flex-1 rounded-xl border border-mono-200 px-3 text-xs" onclick="this.select()">
                <button type="button" class="h-10 rounded-xl border border-mono-200 px-3 text-sm" @click="navigator.clipboard.writeText(@js($generatedLink)); copied = true">
                    <span x-text="copied ? 'Copiado' : 'Copiar link'">Copiar link</span>
                </button>
            </div>
        </x-jr.card>
    @endif

    <x-jr.card>
        <h3 class="font-bold">Convidar pessoa</h3>
        <form wire:submit="invite" class="mt-3 space-y-3">
            <div class="grid gap-3 md:grid-cols-2">
                <x-jr.input label="Nome *" name="inviteName" wire:model="inviteName" maxlength="120" />
                <x-jr.input label="E-mail *" name="inviteEmail" type="email" wire:model="inviteEmail" maxlength="190" />
            </div>
            @error('inviteEmail')<p class="text-xs text-error">{{ $message }}</p>@enderror
            <fieldset>
                <legend class="text-sm font-medium text-mono-700">Módulos liberados</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($moduleLabels as $key => $label)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $key }}" wire:model="inviteModules"> {{ $label }}</label>
                    @endforeach
                </div>
                @error('modules')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
            </fieldset>
            <div class="flex justify-end"><button type="submit" class="h-10 rounded-xl bg-primary-500 px-4 text-sm font-semibold text-white">Gerar convite</button></div>
        </form>
    </x-jr.card>

    <x-jr.card>
        <h3 class="font-bold">Usuários</h3>
        <ul class="mt-3 divide-y divide-mono-100">
            @foreach ($users as $u)
                <li class="py-3" wire:key="user-{{ $u->id }}" data-user="{{ $u->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ $u->name }} @if ($u->is_admin)<span class="ml-1 rounded-full bg-primary-100 px-2 py-0.5 text-xs text-primary-600">Administrador</span>@endif</p>
                            <p class="text-xs text-mono-600">{{ $u->email }} · {{ $u->status === 'blocked' ? 'Bloqueado' : 'Ativo' }}</p>
                        </div>
                        @unless ($u->is_admin || $u->id === auth()->id())
                            <div class="flex gap-2 text-xs">
                                @if ($u->status === 'blocked')
                                    <button type="button" wire:click="reactivate({{ $u->id }})" class="h-9 rounded-xl border border-mono-200 px-3">Reativar</button>
                                @else
                                    <button type="button" wire:click="block({{ $u->id }})" wire:confirm="Bloquear {{ $u->name }}? Ele sai do sistema na próxima ação." class="h-9 rounded-xl border border-error px-3 text-error">Bloquear</button>
                                @endif
                            </div>
                        @endunless
                    </div>
                    @if ($u->is_admin)
                        <p class="mt-1 text-xs text-mono-600">Acesso total, incluindo esta página.</p>
                    @else
                        <div class="mt-2 grid gap-1 sm:grid-cols-2">
                            @foreach ($moduleLabels as $key => $label)
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $key }}" wire:model="editModules.{{ $u->id }}"> {{ $label }}</label>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-end"><button type="button" wire:click="saveModules({{ $u->id }})" class="h-9 rounded-xl border border-mono-200 px-3 text-xs">Salvar módulos</button></div>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-jr.card>

    <x-jr.card>
        <h3 class="font-bold">Convites</h3>
        <ul class="mt-3 divide-y divide-mono-100 text-sm">
            @forelse ($invitations as $inv)
                @php($st = $inv->status())
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="inv-{{ $inv->id }}" data-invitation="{{ $inv->id }}">
                    <span>{{ $inv->name }} <span class="text-xs text-mono-600">{{ $inv->email }} · {{ \App\Models\UserInvitation::STATUS_LABELS[$st] }} · válido até {{ $inv->expires_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · {{ implode(', ', array_map(fn ($m) => $moduleLabels[$m] ?? $m, (array) $inv->modules)) }}</span></span>
                    @if ($st !== 'accepted')
                        <span class="flex gap-2 text-xs">
                            <button type="button" wire:click="regenerate({{ $inv->id }})" wire:confirm="Gerar novo link? O link anterior deixa de funcionar." class="h-9 rounded-xl border border-mono-200 px-3">Gerar novo link</button>
                            @if ($st === 'pending')<button type="button" wire:click="revoke({{ $inv->id }})" wire:confirm="Revogar este convite?" class="h-9 rounded-xl border border-error px-3 text-error">Revogar</button>@endif
                        </span>
                    @endif
                </li>
            @empty
                <li class="py-2 text-mono-500">Nenhum convite.</li>
            @endforelse
        </ul>
        <p class="mt-3 text-xs text-mono-600">Envio automático por e-mail e entrada com Google ficam para depois: dependem de configurar um servidor de e-mail e credenciais Google próprias.</p>
    </x-jr.card>
</div>
