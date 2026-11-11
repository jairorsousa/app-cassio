<?php

use App\Models\MailSenderSetting;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Access;
use App\Support\GmailSend\GmailSendOAuthService;
use App\Support\InvitationMailService;
use App\Support\UserAccessService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $inviteName = '';
    public string $inviteEmail = '';
    public string $invitePhone = '';
    public array $inviteModules = [];
    /** Grupos de Divisões em que a pessoa convidada já entra. */
    public array $inviteGroups = [];
    /** Grupo escolhido para adicionar, por e-mail da pessoa. */
    public array $divAddGroup = [];
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
        $this->editRoles = User::orderBy('id')->get()->mapWithKeys(fn ($u) => [$u->id => \App\Support\RolePolicy::role($u) === 'viewer' ? 'viewer' : 'operator'])->all();
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
            [$inv, $token] = UserAccessService::invite(auth()->user(), $this->inviteName, $this->inviteEmail, $this->inviteModules, $this->invitePhone);
            if (in_array('divisoes', (array) $inv->modules, true)) {
                $svc = app(\App\Domains\Divisions\Services\DivisionService::class);
                foreach (\App\Domains\Divisions\Models\DivisionGroup::whereIn('id', array_map('intval', $this->inviteGroups))->get() as $g) {
                    if (! $g->members()->whereRaw('lower(email) = ?', [$inv->email])->exists()) {
                        $svc->addMember(auth()->user(), $g, $inv->name, $inv->email, $inv->phone);
                    }
                }
            }
            $this->generatedLink = route('invite.accept', ['token' => $token]);
            $this->generatedFor = $inv->name;
            $this->generatedInvitationId = $inv->id;
            $this->generatedToken = $token;
            $this->notice = 'Convite criado. Copie o link ou use "Enviar por e-mail" (envio não é automático). O link vale por 7 dias e só pode ser usado uma vez.';
            $this->reset('inviteName', 'inviteEmail', 'invitePhone', 'inviteModules', 'inviteGroups');
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

    /** @var array<int, string> */
    public array $editRoles = [];

    public function saveRole(int $userId): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($userId) {
            UserAccessService::setRole(auth()->user(), $userId, (string) ($this->editRoles[$userId] ?? 'operator'));
            $this->notice = 'Perfil atualizado. Vale a partir da próxima ação do usuário.';
        });
        $this->loadEdit();
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
            $this->notice = 'E-mail de teste enviado via Gmail OAuth apenas para o próprio remetente.';
        });
    }

    public function disconnectGmail(): void
    {
        $this->resetErrorBag();
        abort_unless((int) auth()->id() === 1, 403);
        app(GmailSendOAuthService::class)->disconnect(auth()->user());
        $this->notice = 'Conexão Gmail de convites removida (tokens locais apagados).';
    }

    private function div(): \App\Domains\Divisions\Services\DivisionService
    {
        return app(\App\Domains\Divisions\Services\DivisionService::class);
    }

    public function divLink(string $email): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($email) {
            $name = \App\Models\UserInvitation::where('email', $email)->orderByDesc('id')->value('name')
                ?? \App\Domains\Divisions\Models\DivisionMember::whereRaw('lower(email) = ?', [$email])->value('name') ?? $email;
            $link = $this->div()->divisionLinkFor(auth()->user(), $name, $email);
            if ($link) {
                $inv = \App\Models\UserInvitation::where('email', $email)->orderByDesc('id')->first();
                $this->generatedLink = $link;
                $this->generatedFor = $inv?->name;
                $this->generatedInvitationId = $inv?->id;
                $this->generatedToken = basename(parse_url($link, PHP_URL_PATH));
                $this->notice = 'Link de convite gerado (o anterior deixou de valer). Copie e envie; nenhum e-mail é enviado.';
            } else {
                $this->notice = 'Essa pessoa já tem conta: o módulo Divisões está liberado para ela.';
            }
            $this->loadEdit();
        });
    }

    public function divRevoke(string $email): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($email) {
            $this->notice = $this->div()->revokeDivisionAccess(auth()->user(), $email);
            $this->generatedLink = null;
            $this->generatedToken = null;
            $this->generatedInvitationId = null;
            $this->loadEdit();
        });
    }

    public function divAddToGroup(string $email): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($email) {
            $key = md5($email);
            $group = \App\Domains\Divisions\Models\DivisionGroup::findOrFail((int) ($this->divAddGroup[$key] ?? 0));
            if ($group->members()->whereRaw('lower(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['divisions' => 'Essa pessoa já está nesse grupo.']);
            }
            $name = \App\Models\User::whereRaw('lower(email) = ?', [$email])->value('name')
                ?? \App\Models\UserInvitation::where('email', $email)->orderByDesc('id')->value('name')
                ?? \App\Domains\Divisions\Models\DivisionMember::whereRaw('lower(email) = ?', [$email])->value('name') ?? $email;
            $this->div()->addMember(auth()->user(), $group, $name, $email);
            unset($this->divAddGroup[$key]);
            $this->notice = "{$name} adicionado(a) ao grupo {$group->name}.";
        });
    }

    public array $divPhone = [];

    public function divSavePhone(string $email): void
    {
        try {
            $this->div()->setPersonPhone(auth()->user(), $email, $this->divPhone[md5(mb_strtolower($email))] ?? '');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('divisions', $e->validator->errors()->first());

            return;
        }
        $this->resetErrorBag('divisions');
        session()->flash('status', 'Celular salvo.');
    }

    public function divRemoveFromGroup(int $memberId): void
    {
        $this->resetErrorBag();
        $this->guarded(function () use ($memberId) {
            $m = \App\Domains\Divisions\Models\DivisionMember::findOrFail($memberId);
            $this->div()->removeMember(auth()->user(), $m);
            $this->notice = "{$m->name} saiu do grupo {$m->group?->name}.";
        });
    }

    public function with(): array
    {
        $smtp = (int) auth()->id() === 1 ? InvitationMailService::settingOrDefaults() : null;

        return [
            'users' => User::orderBy('id')->get(['id', 'name', 'email', 'is_admin', 'role', 'status', 'modules', 'contact_id']),
            'invitations' => UserInvitation::visible()->orderByDesc('id')->limit(50)->get(),
            'moduleLabels' => Access::MODULES,
            'divGroups' => \App\Domains\Divisions\Models\DivisionGroup::where('status', 'approved')->orderBy('name')->get(),
            'divPeople' => $this->div()->people(),
            'canConfigureSmtp' => (int) auth()->id() === 1,
            'smtp' => $smtp,
            // Nunca expor senha / comprimento / valor mascarado
        ];
    }
}; ?>

<x-slot name="header">Usuários e acessos</x-slot>

<div class="mx-auto max-w-4xl space-y-6" data-users-page>
    <div>
        <x-back-button :href="route('settings')" />
        <h2 class="mt-4 text-2xl font-bold tracking-tight">Usuários e acessos</h2>
        <p class="mt-2 text-sm text-mono-600">Convide pessoas e escolha quais módulos cada uma pode usar. O link pode ser copiado manualmente; o envio por e-mail só ocorre quando você clica em "Enviar por e-mail" e o remetente SMTP estiver completo.</p>
    </div>

    @if ($notice)<x-jr.alert variant="success">{{ $notice }}</x-jr.alert>@endif
    @error('users')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('invite')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('inviteMail')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('smtp')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
    @error('smtpTest')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror

    @if ($canConfigureSmtp)
        <x-jr.card data-gmail-oauth>
            <h3 class="font-bold">Remetente de e-mail (Gmail OAuth)</h3>
            <p class="mt-1 text-sm text-mono-600">Somente o administrador principal (id 1). Conecta o Google <strong>só para enviar convites</strong> (escopo <code>gmail.send</code> + verificação de e-mail). Não altera Agenda nem login Google. Remetente fixo <code>{{ $smtp['oauth']['allowed_account'] ?? 'cassio@cassiomota.com' }}</code>.</p>
            @error('gmailOAuth')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
            @if (session('status'))<x-jr.alert variant="success">{{ session('status') }}</x-jr.alert>@endif

            <div class="mt-3 space-y-2 text-sm" data-gmail-oauth-status>
                @if (!($smtp['oauth']['configured'] ?? false))
                    <p class="text-error">Cliente OAuth não configurado no servidor (reaproveita o client da Agenda). Avise o operador.</p>
                @elseif ($smtp['oauth']['connected'] ?? false)
                    <p class="text-primary-600" data-gmail-connected>
                        Conectado: <strong>{{ $smtp['oauth']['account_email'] }}</strong>
                        @if ($smtp['oauth']['connected_at']) · desde {{ $smtp['oauth']['connected_at']->timezone('America/Fortaleza')->format('d/m/Y H:i') }} (Fortaleza)@endif
                        · transporte <code>google-oauth</code>
                    </p>
                    <p class="text-xs text-mono-600">Escopos gravados: {{ implode(' ', $smtp['oauth']['scopes'] ?? []) }}</p>
                    @if (($smtp['oauth']['last_self_test_status'] ?? null) === 'ok')
                        <p class="text-xs text-primary-600" data-smtp-last-test="ok">Último teste: ok{{ $smtp['oauth']['last_self_test_at'] ? ' em '.$smtp['oauth']['last_self_test_at']->timezone('America/Fortaleza')->format('d/m/Y H:i') : '' }}. Conectar ≠ entrega confirmada até o teste explícito.</p>
                    @elseif (($smtp['oauth']['last_self_test_status'] ?? null) === 'failed')
                        <p class="text-xs text-error" data-smtp-last-test="failed">Último teste: falhou. {{ $smtp['oauth']['last_self_test_error'] }} Link manual do convite continua disponível.</p>
                    @else
                        <p class="text-xs text-mono-600" data-smtp-last-test="none">Conectado, mas ainda sem teste de envio. Nenhum e-mail sai só por conectar.</p>
                    @endif
                @else
                    <p data-gmail-disconnected>Status: <strong>não conectado</strong>. Envio por e-mail indisponível (senha de aplicativo não é mais usada automaticamente).</p>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                @if ($smtp['oauth']['configured'] ?? false)
                    @if (!($smtp['oauth']['connected'] ?? false))
                        <a href="{{ route('settings.email.google.connect') }}" class="inline-flex h-10 items-center rounded-xl bg-primary-500 px-4 text-sm font-semibold text-white" data-gmail-connect>Conectar Gmail somente para envio de convites</a>
                    @else
                        <button type="button" wire:click="sendSmtpSelfTest" wire:confirm="Enviar e-mail de teste só para {{ $smtp['oauth']['allowed_account'] }} via Gmail API?" class="h-10 rounded-xl border border-mono-200 px-3 text-sm" data-smtp-self-test>Testar remetente (só para mim)</button>
                        <button type="button" wire:click="disconnectGmail" wire:confirm="Remover tokens Gmail deste sistema? (não revoga no Google automaticamente)" class="h-10 rounded-xl border border-error px-3 text-sm text-error" data-gmail-disconnect>Desconectar</button>
                        <a href="{{ route('settings.email.google.connect') }}" class="inline-flex h-10 items-center rounded-xl border border-mono-200 px-3 text-sm" data-gmail-reconnect>Reconectar</a>
                    @endif
                @endif
            </div>
            <p class="mt-3 text-xs text-mono-600">Callback a registrar no Google Cloud Console: <code>{{ $smtp['oauth']['redirect_uri'] ?? '' }}</code>. Ative a Gmail API. Escopo de correio: só gmail.send.</p>
        </x-jr.card>

        <x-jr.card data-smtp-settings data-smtp-legacy>
            <h3 class="font-bold text-mono-600">Legado SMTP (não usado para envio)</h3>
            <p class="mt-1 text-xs text-mono-600">Mantido só como registro. O envio de convites usa Gmail OAuth quando conectado; não há fallback silencioso para senha de aplicativo.</p>
            @if ($smtp['has_password'] ?? false)
                <p class="mt-2 text-xs text-mono-600">Há senha de aplicativo ainda gravada (oculta).@if (!($smtp['password_format_ok'] ?? false)) <span class="text-error" data-smtp-format-bad> Formato inválido para senha de aplicativo Google.</span>@endif Não é usada automaticamente.</p>
                <button type="button" wire:click="clearSmtpPassword" wire:confirm="Remover a senha de aplicativo legada?" class="mt-2 h-9 rounded-xl border border-error px-3 text-xs text-error" data-smtp-clear>Remover senha legada</button>
            @else
                <p class="mt-2 text-xs text-mono-600">Nenhuma senha SMTP gravada.</p>
            @endif
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
                @php($mailReady = \App\Support\InvitationMailService::isReadyToSend())
                @if ($generatedInvitationId && $mailReady)
                    <button type="button" wire:click="sendInvitationEmail({{ $generatedInvitationId }})" class="h-10 rounded-xl bg-primary-500 px-3 text-sm font-semibold text-white" data-send-invite-email>Enviar por e-mail</button>
                @endif
            </div>
        </x-jr.card>
    @endif

    <x-jr.card>
        <h3 class="font-bold">Convidar pessoa</h3>
        <form wire:submit="invite" class="mt-3 space-y-3">
            <div class="grid gap-3 md:grid-cols-3" data-invite-fields>
                <x-jr.input label="E-mail *" name="inviteEmail" type="email" wire:model="inviteEmail" maxlength="190" />
                <x-jr.input label="Apelido (opcional)" name="inviteName" wire:model="inviteName" maxlength="120" helper="Só para você identificar na lista. Vira o nome real quando a pessoa completar o cadastro." />
                <x-jr.input label="Celular (opcional)" name="invitePhone" type="tel" x-phone placeholder="(99) 99999-9999" maxlength="15" wire:model="invitePhone" />
            </div>
            @error('inviteEmail')<p class="text-xs text-error">{{ $message }}</p>@enderror
            @error('inviteName')<p class="text-xs text-error">{{ $message }}</p>@enderror
            @error('invitePhone')<p class="text-xs text-error">{{ $message }}</p>@enderror
            <fieldset>
                <legend class="text-sm font-medium text-mono-700">Módulos liberados</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($moduleLabels as $key => $label)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $key }}" wire:model.live="inviteModules"> {{ $label }}</label>
                    @endforeach
                </div>
                @error('modules')<p class="mt-1 text-xs text-error">{{ $message }}</p>@enderror
            </fieldset>
            @if (in_array('divisoes', $inviteModules, true) && $divGroups->isNotEmpty())
                <fieldset data-invite-groups>
                    <legend class="text-sm font-medium text-mono-700">Grupos de Divisões em que a pessoa já entra</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($divGroups as $g)<label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $g->id }}" wire:model="inviteGroups"> {{ $g->name }}</label>@endforeach
                    </div>
                </fieldset>
            @endif
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
                            @if ($u->contact_id)<a href="{{ route('contacts.show', $u->contact_id) }}#acesso" class="text-xs font-semibold text-primary-600" data-user-login-link>Trocar e-mail de acesso</a>@endif
                        </div>
                        @if (!($u->is_admin || $u->id === auth()->id()))
                            <div class="flex gap-2 text-xs">
                                @if ($u->status === 'blocked')
                                    <button type="button" wire:click="reactivate({{ $u->id }})" class="h-9 rounded-xl border border-mono-200 px-3">Reativar</button>
                                @else
                                    <button type="button" wire:click="block({{ $u->id }})" wire:confirm="Bloquear {{ $u->name }}? Ele sai do sistema na próxima ação." class="h-9 rounded-xl border border-error px-3 text-error">Bloquear</button>
                                @endif
                            </div>
                        @endif
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
                        <div class="mt-2 flex flex-wrap items-center justify-end gap-2" data-role-editor>
                            <label class="text-xs text-mono-600" for="role-{{ $u->id }}">Perfil</label>
                            <select id="role-{{ $u->id }}" wire:model="editRoles.{{ $u->id }}" class="h-9 rounded-xl border border-mono-200 px-3 text-xs">
                                @foreach (\App\Support\RolePolicy::ROLES as $rk => $rl)<option value="{{ $rk }}">{{ $rl }}</option>@endforeach
                            </select>
                            <button type="button" wire:click="saveRole({{ $u->id }})" class="h-9 rounded-xl border border-mono-200 px-3 text-xs">Salvar perfil</button>
                        </div>
                        <p class="mt-1 text-right text-xs text-mono-600">Operador: lança e edita, não exclui cadastros (contatos, corretores, requisitórios, contas, cartões). Somente leitura: só consulta.</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-jr.card>

    <x-jr.card data-divisions-access>
        <h3 class="font-bold">Divisões · pessoas, convites e grupos</h3>
        <p class="mt-1 text-xs text-mono-600">Os convites gerados aqui e pelo botão do módulo Divisões são os mesmos registros. Gerar novo link invalida o anterior. Nenhum e-mail é enviado.</p>
        @error('divisions')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
        <ul class="mt-3 divide-y divide-mono-100 text-sm">
            @forelse ($divPeople as $p)
                @php($inv = $p['invitation'])
                @php($u = $p['user'])
                @php($status = $u ? ($u->status === 'blocked' ? 'Conta bloqueada' : (in_array('divisoes', (array) $u->modules, true) ? 'Convite aceito · com acesso' : 'Conta sem o módulo Divisões')) : ($inv ? 'Convite '.mb_strtolower(\App\Models\UserInvitation::STATUS_LABELS[$inv->status()]) : 'Sem convite'))
                <li class="py-3" wire:key="div-{{ md5($p['email']) }}" data-div-person="{{ $p['email'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0"><p class="font-semibold">{{ $p['name'] }}</p>
                            @unless ($u)<x-invite-link :invitation="$inv" :name="$p['name']" :action="'divLink('.\Illuminate\Support\Js::from($p['email']).')'" />@endunless
                            <p class="text-xs text-mono-600">{{ $p['email'] }} · <span data-div-status>{{ $status }}</span>@if ($inv && ! $u) · convidado em {{ $inv->created_at?->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · válido até {{ $inv->expires_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }}@endif</p></div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            @if (! $u || $u->status === 'blocked' || ! in_array('divisoes', (array) $u->modules, true))
                                <button type="button" wire:click="divLink(@js($p['email']))" class="h-9 rounded-xl border border-mono-200 px-3" data-div-link>{{ $u ? 'Liberar acesso' : ($inv ? 'Gerar novo link' : 'Gerar link de convite') }}</button>
                            @endif
                            @if (($u && $u->status !== 'blocked' && in_array('divisoes', (array) $u->modules, true)) || ($inv && ! $u && $inv->status() === 'pending'))
                                <button type="button" wire:click="divRevoke(@js($p['email']))" wire:confirm="Revogar o acesso de {{ $p['name'] }} a Divisões? O histórico dos grupos fica guardado." class="h-9 rounded-xl border border-error px-3 text-error" data-div-revoke>Revogar acesso</button>
                            @endif
                        </div>
                    </div>
                    @php($phoneKey = md5(mb_strtolower($p['email'])))
                    @php($curPhone = $p['members']->firstWhere('phone', '!=', null)?->phoneDisplay() ?? '')
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs" data-div-phone x-data x-init="if (! $wire.get('divPhone.{{ $phoneKey }}')) $wire.set('divPhone.{{ $phoneKey }}', @js($curPhone), false)">
                        <span class="text-mono-600">Celular:</span>
                        <input type="tel" x-phone wire:model="divPhone.{{ $phoneKey }}" placeholder="(99) 99999-9999" maxlength="15" class="h-9 w-40 rounded-xl border border-mono-200 px-2 text-xs">
                        <button type="button" wire:click="divSavePhone(@js($p['email']))" class="h-9 rounded-xl border border-mono-200 px-3" data-div-save-phone>Salvar celular</button>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @forelse ($p['members'] as $m)
                            <span class="inline-flex items-center gap-1 rounded-full bg-mono-100 px-2 py-0.5 text-xs" data-div-group>{{ $m->group->name }}@if ($m->group->status !== 'approved') ({{ mb_strtolower($m->group->statusLabel()) }})@endif
                                <button type="button" wire:click="divRemoveFromGroup({{ $m->id }})" wire:confirm="Tirar {{ $m->name }} do grupo {{ $m->group->name }}?" class="text-error" title="Remover do grupo">×</button></span>
                        @empty
                            <span class="text-xs text-mono-500">Em nenhum grupo.</span>
                        @endforelse
                        @php($available = $divGroups->reject(fn ($g) => $p['members']->pluck('group_id')->contains($g->id)))
                        @if ($available->isNotEmpty())
                            <select wire:model="divAddGroup.{{ md5($p['email']) }}" class="h-9 rounded-xl border border-mono-200 px-2 text-xs"><option value="">Adicionar a um grupo…</option>@foreach ($available as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select>
                            <button type="button" wire:click="divAddToGroup(@js($p['email']))" class="h-9 rounded-xl border border-mono-200 px-3 text-xs" data-div-add>Adicionar</button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-2 text-mono-500">Ninguém com acesso a Divisões ainda. Use "Convidar pessoa" acima marcando Divisões.</li>
            @endforelse
        </ul>
    </x-jr.card>

    <x-jr.card>
        <h3 class="font-bold">Convites</h3>
        <ul class="mt-3 divide-y divide-mono-100 text-sm">
            @forelse ($invitations as $inv)
                @php($st = $inv->status())
                @php($mailReady = \App\Support\InvitationMailService::isReadyToSend())
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="inv-{{ $inv->id }}" data-invitation="{{ $inv->id }}">
                    <span class="min-w-0">{{ $inv->name }}<x-invite-link :invitation="$inv" :name="$inv->name" :action="$st === 'accepted' ? null : 'regenerate('.$inv->id.')'" /> <span class="text-xs text-mono-600">{{ $inv->email }} · {{ \App\Models\UserInvitation::STATUS_LABELS[$st] }} · {{ $inv->deliveryLabel() }}@if ($inv->email_last_error) ({{ $inv->email_last_error }})@endif · válido até {{ $inv->expires_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · {{ implode(', ', array_map(fn ($m) => $moduleLabels[$m] ?? $m, (array) $inv->modules)) }}</span></span>
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
