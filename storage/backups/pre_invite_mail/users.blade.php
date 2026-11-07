<?php

use App\Models\MailSenderSetting;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Access;
use App\Support\InvitationMailService;
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
    public ?int $generatedInvitationId = null;
    /** Token em memória só até o próximo reload — usado no envio explícito sem regenerar de novo. */
    public ?string $generatedToken = null;
    public array $editModules = [];
    public ?string $notice = null;

    public string $smtpUsername = 'cassio@cassiomota.com';
    public string $smtpHost = 'smtp.gmail.com';
    public int $smtpPort = 587;
    /** Só escrita; limpa após salvar; nunca reidratada com valor do banco. */
    public string $smtpPassword = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->loadEdit();
        $this->loadSmtpForm();
    }

    private function loadEdit(): void
    {
        $this->editModules = User::orderBy('id')->get()->mapWithKeys(fn ($u) => [$u->id => Access::clean((array) $u->modules)])->all();
    }

    private function loadSmtpForm(): void
    {
        if ((int) auth()->id() !== 1) {
            return;
        }
        $s = InvitationMailService::settingOrDefaults();
        $this->smtpUsername = $s['username'];
        $this->smtpHost = $s['host'];
        $this->smtpPort = (int) $s['port'];
        $this->smtpPassword = '';
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
            $this->generatedInvitationId = $inv->id;
            $this->generatedToken = $token;
            $this->notice = 'Convite criado. Copie o link ou use "Enviar por e-mail" (envio não é automático). O link vale por 7 dias e só pode ser usado uma vez.';
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
            $this->generatedInvitationId = $id;
            $this->generatedToken = $token;
            $this->notice = 'Novo link gerado. O link anterior deixou de funcionar. O e-mail não foi enviado automaticamente.';
        });
    }

    public function sendInvitationEmail(?int $id = null): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($id) {
            $targetId = $id ?? $this->generatedInvitationId;
            if (! $targetId) {
                throw ValidationException::withMessages(['inviteMail' => 'Gere um convite antes de enviar o e-mail.']);
            }
            $known = ($this->generatedInvitationId === $targetId) ? $this->generatedToken : null;
            [$inv, $token] = InvitationMailService::queueInvitationEmail(auth()->user(), (int) $targetId, $known);
            $this->generatedLink = route('invite.accept', ['token' => $token]);
            $this->generatedFor = $inv->name;
            $this->generatedInvitationId = $inv->id;
            $this->generatedToken = $token;
            $this->notice = 'Convite colocado na fila de e-mail. O status aparece na lista abaixo. O link manual continua disponível.';
        });
    }

    public function revoke(int $id): void
    {
        UserAccessService::revoke(auth()->user(), $id);
        $this->generatedLink = null;
        $this->generatedToken = null;
        $this->generatedInvitationId = null;
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
            $this->notice = 'Uuário bloqueado. Ele sai do sistema na próxima ação.';
        });
    }

    public function reactivate(int $userId): void
    {
        $this->guarded(function () use ($userId) {
            UserAccessService::reactivate(auth()->user(), $userId);
            $this->notice = 'Usuário reativado com os mesmos módulos de antes.';
        });
    }

    public function saveSmtp(): void
    {
        $this->resetErrorBag();
        abort_unless((int) auth()->id() === 1, 403);
        $this->guarded(function () {
            $pwd = $this->smtpPassword;
            InvitationMailService::saveSettings(
                auth()->user(),
                $this->smtpUsername,
                $this->smtpHost,
                (int) $this->smtpPort,
                $pwd !== '' ? $pwd : null,
                false,
            );
            $this->smtpPassword = '';
            $ready = InvitationMailService::settingOrDefaults()['is_ready'];
            $this->notice = $ready
                ? 'Remetente SMTP salvo com senha. O envio de convites por e-mail fica disponível no botão de cada convite. Isto não é um teste de entrega.'
                : 'Remetente SMTP salvo (sem senha nova). Envio por e-mail só funciona depois que a senha de aplicativo for gravada neste formulário.';
            $this->loadSmtpForm();
        });
    }

    public function clearSmtpPassword(): void
    {
        $this->resetErrorBag();
        abort_unless((int) auth()->id() === 1, 403);
        $this->guarded(function () {
            InvitationMailService::clearPassword(auth()->user());
            $this->smtpPassword = '';
            $this->notice = 'Senha de aplicativo removida. Envios por e-mail ficam indisponíveis até gravar outra.';
            $this->loadSmtpForm();
        });
    }

    public function sendSmtpSelfTest(): void
    {
        $this->resetErrorBag();
        abort_unless((int) auth()->id() === 1, 403);
        $this->guarded(function () {
            InvitationMailService::sendSelfTest(auth()->user());
            $this->notice = 'E-mail de teste enviado apenas para o próprio remetente configurado.';
        });
    }

    public function with(): array
    {
        $smtp = (int) auth()->id() === 1 ? InvitationMailService::settingOrDefaults() : null;

        return [
            'users' => User::orderBy('id')->get(['id', 'name', 'email', 'is_admin', 'status', 'modules']),
            'invitations' => UserInvitation::orderByDesc('id')->limit(50)->get(),
            'moduleLabels' => Access::MODULES,
            'canConfigureSmtp' => (int) auth()->id() === 1,
            'smtp' => $smtp,
            // Nunca expor senha / comprimento / valor mascarado
        ];
    }
}; ?>

<x-slot name="header">Usuários e acessos</x-slot>

<div class="mx-auto max-w-4xl space-y-6" data-users-page>
    <div>
        <a href="{{ route('settings') }}" class="text-sm text-primary-600">← Configurações</a>
        <h2 class="mt-2 text-2xl font-bold tracking-tight">Usuários e acessos</h2>
        <p class="mt-2 text-sm text-mono-600">Convide pessoas e escolha quais módulos cada uma pode usar. O link pode ser copiado manualmente; o envio por e-mail só ocorre quando você clica em "Enviar por e-mail" e o remetente SMTP estiver completo.</p>
    </div>

    @if ($notice)<x-jr.alert variant="success">{{ $notice }}</x-jr.alert>@endif
    @error('users')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('invite')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('inviteMail')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('smtp')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('smtpTest')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror

    @if ($canConfigureSmtp)
        <x-jr.card data-smtp-settings>
            <h3 class="font-bold">Remetente de e-mail (Google SMTP)</h3>
            <p class="mt-1 text-sm text-mono-600">Somente o administrador principal (id 1) configura. Remetente fixo <code>{{ \App\Models\MailSenderSetting::ALLOWED_USERNAME }}</code>, host <code>smtp.gmail.com</code>, porta 587 TLS. A senha de aplicativo é gravada criptografada; nunca aparece de volta neste formulário.</p>
            <form wire:submit="saveSmtp" class="mt-3 space-y-3" autocomplete="off">
                <div class="grid gap-3 md:grid-cols-3">
                    <x-jr.input label="Remetente / usuário *" name="smtpUsername" wire:model="smtpUsername" maxlength="190" data-smtp-username />
                    <x-jr.input label="Host *" name="smtpHost" wire:model="smtpHost" maxlength="190" data-smtp-host />
                    <x-jr.input label="Porta *" name="smtpPort" type="number" wire:model="smtpPort" data-smtp-port />
                </div>
                @error('smtpUsername')<p class="text-xs text-error">{{ $message }}</p>@enderror
                @error('smtpHost')<p class="text-xs text-error">{{ $message }}</p>@enderror
                @error('smtpPort')<p class="text-xs text-error">{{ $message }}</p>@enderror
                <div>
                    <x-jr.input label="Senha de aplicativo Google" name="smtpPassword" type="password" wire:model="smtpPassword" maxlength="200" autocomplete="new-password" data-smtp-password />
                    <p class="mt-1 text-xs text-mono-600">
                        @if ($smtp['has_password'] ?? false)
                            Senha já gravada (valor oculto). Deixe em branco ao salvar para manter a atual.
                        @else
                            Ainda sem senha: dá para salvar remetente/host agora; o envio só fica disponível depois de gravar a senha aqui.
                        @endif
                    </p>
                    @error('smtpPassword')<p class="text-xs text-error">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="space-y-1 text-xs text-mono-600" data-smtp-status>
                        @if ($smtp['is_ready'] ?? false)
                            <p>Configuração: senha gravada (remetente completo). Isso <strong>não</strong> confirma entrega — só o teste explícito ou um envio real mostram o resultado.</p>
                        @else
                            <p>Configuração incompleta para envio (falta senha ou remetente fora do padrão).</p>
                        @endif
                        @if (($smtp['last_self_test_status'] ?? null) === 'ok')
                            <p class="text-primary-600" data-smtp-last-test="ok">Último teste do remetente: ok em {{ optional($smtp['last_self_test_at'])->timezone('America/Fortaleza')?->format('d/m/Y H:i') }} (Fortaleza). Não substitui o envio de cada convite.</p>
                        @elseif (($smtp['last_self_test_status'] ?? null) === 'failed')
                            <p class="text-error" data-smtp-last-test="failed">Último teste do remetente: falhou{{ $smtp['last_self_test_at'] ? ' em '.$smtp['last_self_test_at']->timezone('America/Fortaleza')->format('d/m/Y H:i').' (Fortaleza)' : '' }}. {{ $smtp['last_self_test_error'] }} O link manual do convite continua disponível.</p>
                        @elseif ($smtp['is_ready'] ?? false)
                            <p data-smtp-last-test="none">Nenhum teste bem-sucedido registrado ainda — não trate a configuração como entrega confirmada.</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($smtp['has_password'] ?? false)
                            <button type="button" wire:click="clearSmtpPassword" wire:confirm="Remover a senha de aplicativo salva?" class="h-10 rounded-xl border border-error px-3 text-sm text-error" data-smtp-clear>Remover senha</button>
                            <button type="button" wire:click="sendSmtpSelfTest" wire:confirm="Enviar e-mail de teste só para {{ $smtp['username'] }}?" class="h-10 rounded-xl border border-mono-200 px-3 text-sm" data-smtp-self-test>Testar remetente (só para mim)</button>
                        @endif
                        <button type="submit" class="h-10 rounded-xl bg-primary-500 px-4 text-sm font-semibold text-white" data-smtp-save>Salvar remetente</button>
                    </div>
                </div>
            </form>
        </x-jr.card>
    @endif

    @if ($generatedLink)
        <x-jr.card data-generated-link>
            <h3 class="font-bold">Link de convite{{ $generatedFor ? ' para '.$generatedFor : '' }}</h3>
            <p class="mt-1 text-xs text-mono-600">Este link aparece só agora. Se perder, gere um novo.</p>
            <div class="mt-2 flex flex-wrap gap-2" x-data="{ copied: false }">
                <input type="text" readonly value="{{ $generatedLink }}" class="h-10 min-w-0 flex-1 rounded-xl border border-mono-200 px-3 text-xs" onclick="this.select()">
                <button type="button" class="h-10 rounded-xl border border-mono-200 px-3 text-sm" @click="navigator.clipboard.writeText(@js($generatedLink)); copied = true">
                    <span x-text="copied ? 'Copiado' : 'Copiar link'">Copiar link</span>
                </button>
                @php($mailReady = \App\Models\MailSenderSetting::current()?->isReadyToSend())
                @if ($generatedInvitationId && $mailReady)
                    <button type="button" wire:click="sendInvitationEmail({{ $generatedInvitationId }})" class="h-10 rounded-xl bg-primary-500 px-3 text-sm font-semibold text-white" data-send-invite-email>Enviar por e-mail</button>
                @endif
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
                @php($mailReady = \App\Models\MailSenderSetting::current()?->isReadyToSend())
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="inv-{{ $inv->id }}" data-invitation="{{ $inv->id }}">
                    <span>{{ $inv->name }} <span class="text-xs text-mono-600">{{ $inv->email }} · {{ \App\Models\UserInvitation::STATUS_LABELS[$st] }} · {{ $inv->deliveryLabel() }}@if ($inv->email_last_error) ({{ $inv->email_last_error }})@endif · válido até {{ $inv->expires_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · {{ implode(', ', array_map(fn ($m) => $moduleLabels[$m] ?? $m, (array) $inv->modules)) }}</span></span>
                    @if ($st !== 'accepted')
                        <span class="flex flex-wrap gap-2 text-xs">
                            <button type="button" wire:click="regenerate({{ $inv->id }})" wire:confirm="Gerar novo link? O link anterior deixa de funcionar." class="h-9 rounded-xl border border-mono-200 px-3">Gerar novo link</button>
                            @if ($st === 'pending' && $mailReady && $inv->email_delivery_status !== 'queued')
                                <button type="button" wire:click="sendInvitationEmail({{ $inv->id }})" wire:confirm="Enviar este convite por e-mail? Se não houver link recente na tela, um novo link será gerado." class="h-9 rounded-xl border border-primary-500 px-3 text-primary-600" data-send-invite-row>Enviar por e-mail</button>
                            @endif
                            @if ($st === 'pending')<button type="button" wire:click="revoke({{ $inv->id }})" wire:confirm="Revogar este convite?" class="h-9 rounded-xl border border-error px-3 text-error">Revogar</button>@endif
                        </span>
                    @endif
                </li>
            @empty
                <li class="py-2 text-mono-500">Nenhum convite.</li>
            @endforelse
        </ul>
        <p class="mt-3 text-xs text-mono-600">Criar ou regenerar convite não envia e-mail sozinho. O envio usa a fila Redis já em execução no servidor; o status fica registrado sem guardar a senha nem o token cru.</p>
    </x-jr.card>
</div>
