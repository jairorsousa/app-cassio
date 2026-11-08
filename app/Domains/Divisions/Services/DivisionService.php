<?php

namespace App\Domains\Divisions\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Services\InvoiceService;
use App\Domains\Divisions\Models\DivisionCategory;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Divisions\Models\DivisionAudit;
use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Models\DivisionSettlement;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\UserAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Regras do módulo Divisões. Valores internos em centavos. */
class DivisionService
{
    public const MODULE = 'divisoes';

    // ---------- Acesso ----------

    public function canView(User $user, DivisionGroup $group): bool
    {
        if (! $user->canAccessModule(self::MODULE)) {
            return false;
        }

        return $user->isAdmin() || $group->loadMissing('members')->memberFor($user) !== null;
    }

    public function assertView(User $user, DivisionGroup $group): void
    {
        abort_unless($this->canView($user, $group), 403);
    }

    public function assertAdmin(?User $user): User
    {
        abort_unless($user && $user->isActive() && $user->isAdmin(), 403);

        return $user;
    }

    /** Liga ao usuário as participações que tinham só o e-mail dele (depois do convite aceito). */
    public function claimMemberships(User $user): void
    {
        DivisionMember::whereNull('user_id')->whereNotNull('email')->whereNull('approval_status')
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->update(['user_id' => $user->id]);
        $this->fillPhonesFromContacts($user);
    }

    /**
     * Preenche o celular do participante com o do Contato do usuário ligado, só quando o participante está sem número.
     * Número digitado manualmente nunca é sobrescrito. Devolve quantos participantes receberam celular.
     */
    public function fillPhonesFromContacts(?User $user = null, ?int $memberId = null): int
    {
        $q = DivisionMember::whereNull('phone')->where('is_owner', false)->whereNotNull('user_id');
        if ($user) {
            $q->where('user_id', $user->id);
        }
        if ($memberId) {
            $q->whereKey($memberId);
        }
        $n = 0;
        foreach ($q->get() as $m) {
            $u = User::find($m->user_id);
            $contact = $u?->contact_id ? \App\Domains\Contacts\Models\Contact::find($u->contact_id) : null;
            $raw = $contact ? ($contact->phone ?: (((array) $contact->phones)[0] ?? null)) : null;
            try {
                $e164 = $this->normalizePhone($raw);
            } catch (ValidationException) {
                $e164 = null;
            }
            if ($e164 && DivisionMember::whereKey($m->id)->whereNull('phone')->update(['phone' => $e164])) {
                $n++;
            }
        }

        return $n;
    }

    private function audit(DivisionGroup $group, ?User $user, string $action, array $details = [], ?int $expenseId = null, ?int $settlementId = null): void
    {
        DivisionAudit::create(['group_id' => $group->id, 'expense_id' => $expenseId, 'settlement_id' => $settlementId,
            'user_id' => $user?->id, 'action' => $action, 'details' => $details ?: null]);
    }

    private function ownerUser(): ?User
    {
        return User::where('is_admin', true)->orderBy('id')->first();
    }

    // ---------- Grupos ----------

    /** Cassio cria direto; participante cria um pedido "Aguardando aprovação". */
    public function createGroup(User $actor, array $data): DivisionGroup
    {
        abort_unless($actor->canAccessModule(self::MODULE), 403);
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? '');
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['groupName' => 'Informe o nome do grupo.']);
        }
        if (! array_key_exists($type, DivisionGroup::TYPES)) {
            throw ValidationException::withMessages(['groupType' => 'Escolha o tipo do grupo.']);
        }

        return DB::transaction(function () use ($actor, $data, $name, $type) {
            $admin = $actor->isAdmin();
            $group = DivisionGroup::create([
                'name' => $name, 'type' => $type, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'requires_approval' => (bool) ($data['requires_approval'] ?? true),
                'status' => $admin ? 'approved' : 'pending',
                'requested_by' => $admin ? null : $actor->id, 'created_by' => $actor->id, 'decided_at' => $admin ? now() : null,
            ]);
            $owner = $admin ? $actor : $this->ownerUser();
            if ($owner) {
                $group->members()->create(['name' => $owner->name, 'email' => $owner->email, 'user_id' => $owner->id, 'is_owner' => true]);
            }
            if (! $admin) {
                $group->members()->create(['name' => $actor->name, 'email' => $actor->email, 'user_id' => $actor->id]);
            }
            $this->audit($group, $actor, $admin ? 'group_created' : 'group_requested', ['nome' => $name]);

            return $group;
        });
    }

    public function decideGroup(?User $actor, DivisionGroup $group, bool $approve, ?string $reason = null): void
    {
        $actor = $this->assertAdmin($actor);
        if ($group->status !== 'pending') {
            throw ValidationException::withMessages(['group' => 'Este grupo não está aguardando aprovação.']);
        }
        $group->update(['status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'rejection_reason' => $approve ? null : ($reason ?: null)]);
        $this->audit($group, $actor, $approve ? 'group_approved' : 'group_rejected', $reason ? ['motivo' => $reason] : []);
    }

    public function updateGroup(?User $actor, DivisionGroup $group, array $data): void
    {
        $actor = $this->assertAdmin($actor);
        $name = trim((string) ($data['name'] ?? $group->name));
        $type = (string) ($data['type'] ?? $group->type);
        if ($name === '' || ! array_key_exists($type, DivisionGroup::TYPES)) {
            throw ValidationException::withMessages(['groupName' => 'Informe nome e tipo válidos.']);
        }
        $group->update(['name' => $name, 'type' => $type, 'requires_approval' => (bool) ($data['requires_approval'] ?? $group->requires_approval),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null]);
        $this->audit($group, $actor, 'group_updated', ['nome' => $name, 'aprovação' => $group->requires_approval ? 'sim' : 'não']);
    }

    /** Cassio ou participante do grupo (aprovado) pode incluir pessoas. */
    private function assertCanAddPeople(?User $actor, DivisionGroup $group): User
    {
        abort_unless($actor && $actor->isActive(), 403);
        if ($actor->isAdmin()) {
            return $actor;
        }
        $this->assertView($actor, $group);
        abort_unless($group->status === 'approved' && $group->loadMissing('members')->memberFor($actor), 403);

        return $actor;
    }

    /**
     * Cassio adiciona direto. Participante: quem já tem conta entra direto; pessoa externa (sem conta) fica
     * "Aguardando aprovação do gestor": fora das divisões e sem convite até o Cassio aprovar.
     */
    public function addMember(?User $actor, DivisionGroup $group, string $name, ?string $email, ?string $phone = null): DivisionMember
    {
        $actor = $this->assertCanAddPeople($actor, $group);
        $name = trim($name);
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        if ($name === '' && $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $name = \App\Support\UserAccessService::nickname('', $email); // apelido provisório até o cadastro
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['memberName' => 'Informe o nome do participante.']);
        }
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['memberEmail' => 'E-mail inválido.']);
        }
        if ($email !== null && $group->members()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['memberEmail' => 'Este e-mail já participa do grupo.']);
        }
        try {
            $phone = $this->normalizePhone($phone);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['memberPhone' => 'Celular inválido. Use (99) 99999-9999.']);
        }
        $user = $email ? User::whereRaw('lower(email) = ?', [$email])->first() : null;
        $pending = ! $actor->isAdmin() && ! $user;
        $member = $group->members()->create(['name' => $name, 'email' => $email, 'phone' => $phone, 'user_id' => $user?->id]
            + ($pending ? ['approval_status' => 'pending', 'active' => false, 'requested_by' => $actor->id] : []));
        if ($user && ! $phone) {
            $this->fillPhonesFromContacts($user, $member->id);
            $member->refresh();
        }
        if ($user && ! $actor->isAdmin() && ! $user->isAdmin() && ! in_array(self::MODULE, (array) $user->modules, true) && ($owner = $this->ownerUser())) {
            // Pessoa já cadastrada adicionada por participante: libera só Divisões (sem convite novo).
            $this->divisionLinkFor($owner, $member->name, $user->email);
        }
        $this->audit($group, $actor, $pending ? 'member_requested' : 'member_added', ['nome' => $name]);

        return $member;
    }

    /** Cassio aprova (gera o convite normalmente e devolve o link) ou recusa (remove) a pessoa externa pedida. */
    public function decideMember(?User $actor, DivisionMember $member, bool $approve): ?string
    {
        $actor = $this->assertAdmin($actor);
        if (! $member->isPending()) {
            throw ValidationException::withMessages(['divisions' => 'Este participante não está aguardando aprovação.']);
        }
        $group = $member->group;
        if (! $approve) {
            $this->audit($group, $actor, 'member_rejected', ['nome' => $member->name]);
            $member->delete();

            return null;
        }
        $member->update(['approval_status' => null, 'active' => true]);
        $this->audit($group, $actor, 'member_approved', ['nome' => $member->name]);

        return $member->email ? $this->inviteMember($actor, $member->fresh()) : null;
    }

    /**
     * Pessoas já cadastradas (usuários, participantes de outros grupos e Contatos) para adicionar ao grupo.
     * Busca por nome, e-mail ou celular; quem já está no grupo não aparece. Uma linha por pessoa (por e-mail quando houver).
     *
     * @return list<array{key:string,name:string,email:?string,phone:?string,source:string,has_account:bool}>
     */
    public function searchExistingPeople(DivisionGroup $group, string $term, int $limit = 8, bool $full = true): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.mb_strtolower($term).'%';
        $digits = \App\Support\BrazilianDocuments::digits($term);
        $inGroupEmails = $group->members()->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $inGroupUsers = $group->members()->whereNotNull('user_id')->pluck('user_id')->all();
        $out = [];
        $put = function (string $key, string $name, ?string $email, ?string $phone, string $source) use (&$out, $inGroupEmails) {
            $email = $email ? mb_strtolower(trim($email)) : null;
            if ($email && in_array($email, $inGroupEmails, true)) {
                return;
            }
            $k = $email ? 'e:'.$email : $key;
            if (isset($out[$k])) {
                $out[$k]['phone'] ??= $phone;

                return;
            }
            $out[$k] = ['key' => $key, 'name' => $name, 'email' => $email, 'phone' => $phone, 'source' => $source,
                'has_account' => $email ? User::whereRaw('lower(email) = ?', [$email])->exists() : false];
        };
        $phoneMatch = fn (?string $p) => $digits !== '' && strlen($digits) >= 4 && str_contains(\App\Support\BrazilianDocuments::digits($p), $digits);

        foreach (User::where('is_admin', false)->whereNotIn('id', $inGroupUsers)->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]))->limit(20)->get() as $u) {
            $put('u:'.$u->id, $u->name, $u->email, null, 'Usuário com conta');
        }
        if (! $full) {
            // Participante só encontra quem já tem conta no sistema (não vê Contatos nem pessoas de outros grupos).
            return array_slice(array_values($out), 0, $limit);
        }
        $members = DivisionMember::where('group_id', '!=', $group->id)->whereNull('approval_status')->where('is_owner', false)->with('group')->get()
            ->filter(fn ($m) => str_contains(mb_strtolower($m->name), mb_strtolower($term)) || str_contains(mb_strtolower((string) $m->email), mb_strtolower($term)) || $phoneMatch($m->phone));
        foreach ($members as $m) {
            if ($m->user_id && in_array($m->user_id, $inGroupUsers, true)) {
                continue;
            }
            $put('m:'.$m->id, $m->name, $m->email, $m->phone, 'Participante de '.$m->group?->name);
        }
        $contacts = \App\Domains\Contacts\Models\Contact::where('status', true)->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like])->orWhere('phone', 'like', '%'.$term.'%'))->limit(20)->get();
        if ($digits !== '' && strlen($digits) >= 4) {
            $contacts = $contacts->merge(\App\Domains\Contacts\Models\Contact::where('status', true)->whereNotNull('phone')->get()->filter(fn ($c) => $phoneMatch($c->phone)));
        }
        foreach ($contacts->unique('id') as $c) {
            $put('c:'.$c->id, $c->name, $c->email, $c->phone ? $this->safePhone($c->phone) : null, 'Contato');
        }

        return array_slice(array_values($out), 0, $limit);
    }

    private function safePhone(?string $raw): ?string
    {
        try {
            return $this->normalizePhone($raw);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Adiciona ao grupo uma pessoa já cadastrada. Se ela já tem conta, liga a conta e libera Divisões sem gerar convite.
     * Evita duplicar no mesmo grupo (mesmo e-mail ou mesma conta).
     */
    public function addExistingPerson(?User $actor, DivisionGroup $group, string $key): DivisionMember
    {
        $actor = $this->assertCanAddPeople($actor, $group);
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
        $id = (int) $id;
        if (! $actor->isAdmin() && $type !== 'u') {
            throw ValidationException::withMessages(['personSearch' => 'Escolha uma pessoa da lista.']);
        }
        [$name, $email, $phone] = match ($type) {
            'u' => (function () use ($id) {
                $u = User::where('is_admin', false)->findOrFail($id);
                return [$u->name, $u->email, null];
            })(),
            'm' => (function () use ($id) {
                $m = DivisionMember::where('is_owner', false)->findOrFail($id);
                return [$m->name, $m->email, $m->phone];
            })(),
            'c' => (function () use ($id) {
                $c = \App\Domains\Contacts\Models\Contact::findOrFail($id);
                return [$c->name, $c->email, $this->safePhone($c->phone)];
            })(),
            default => throw ValidationException::withMessages(['personSearch' => 'Escolha uma pessoa da lista.']),
        };
        $email = $email ? mb_strtolower(trim($email)) : null;
        if (! $phone && $email) {
            // Mesmo celular que a pessoa já tem em outro grupo.
            $phone = DivisionMember::whereRaw('lower(email) = ?', [$email])->whereNotNull('phone')->value('phone');
        }
        $user = $email ? User::whereRaw('lower(email) = ?', [$email])->first() : null;
        if (($email && $group->members()->whereRaw('lower(email) = ?', [$email])->exists()) || ($user && $group->members()->where('user_id', $user->id)->exists())) {
            throw ValidationException::withMessages(['personSearch' => $name.' já participa deste grupo.']);
        }
        if (! $email && $group->members()->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->exists()) {
            throw ValidationException::withMessages(['personSearch' => 'Já existe um participante com este nome no grupo.']);
        }
        $member = $this->addMember($actor, $group, $name, $email, $phone ? '+'.ltrim($phone, '+') : null);
        if ($actor->isAdmin() && $user && ! $user->isAdmin() && ! in_array(self::MODULE, (array) $user->modules, true)) {
            // Conta existente: só libera o módulo Divisões (nenhum convite é criado). Por participante, o addMember já liberou.
            $this->inviteMember($actor, $member->fresh());
        }

        return $member->fresh();
    }

    /**
     * Gera o link de convite (o mesmo sistema da Transmissão), liberando só o módulo Divisões. Não envia e-mail.
     * Se a pessoa já tem conta, só libera o módulo e devolve null.
     */
    public function inviteMember(?User $actor, DivisionMember $member): ?string
    {
        $actor = $this->assertAdmin($actor);
        if (! $member->email) {
            throw ValidationException::withMessages(['memberEmail' => 'Cadastre o e-mail do participante para gerar o convite.']);
        }
        if ($member->isPending()) {
            throw ValidationException::withMessages(['divisions' => 'Aprove o participante antes de gerar o convite.']);
        }
        $link = $this->divisionLinkFor($actor, $member->name, $member->email);
        $member->refresh();
        if ($link) {
            $this->audit($member->group, $actor, 'member_invited', ['nome' => $member->name]);
        }

        return $link;
    }

    // ---------- Gestão de acesso (Configurações › Usuários e botão do módulo usam o mesmo registro) ----------

    /**
     * Link de convite para Divisões. Reaproveita o convite existente da pessoa (gera novo link no MESMO registro);
     * só cria um convite novo quando não há nenhum. Quem já tem conta só ganha o módulo (retorna null).
     */
    public function divisionLinkFor(?User $actor, string $name, string $email): ?string
    {
        $actor = $this->assertAdmin($actor);
        $email = mb_strtolower(trim($email));
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if ($user) {
            if ($user->isAdmin()) {
                return null;
            }
            if (! in_array(self::MODULE, (array) $user->modules, true)) {
                UserAccessService::setModules($actor, $user->id, array_merge((array) $user->modules, [self::MODULE]));
            }
            if (! $user->isActive()) {
                UserAccessService::reactivate($actor, $user->id);
            }
            DivisionMember::whereNull('user_id')->whereRaw('lower(email) = ?', [$email])->update(['user_id' => $user->id]);
            $this->fillPhonesFromContacts($user);

            return null;
        }
        $inv = UserInvitation::where('email', $email)->whereNull('accepted_at')->orderByDesc('id')->first();
        if ($inv) {
            if (! in_array(self::MODULE, (array) $inv->modules, true)) {
                $inv->update(['modules' => \App\Support\Access::clean(array_merge((array) $inv->modules, [self::MODULE]))]);
            }
            UserInvitation::where('email', $email)->whereNull('accepted_at')->where('id', '!=', $inv->id)->delete();
            $token = UserAccessService::regenerate($actor, $inv->id);
        } else {
            [, $token] = UserAccessService::invite($actor, $name, $email, [self::MODULE]);
        }

        return route('invite.accept', ['token' => $token]);
    }

    /** Tira o acesso a Divisões: revoga convites pendentes e remove o módulo (sem outro módulo, a conta é bloqueada). Histórico fica. */
    public function revokeDivisionAccess(?User $actor, string $email): string
    {
        $actor = $this->assertAdmin($actor);
        $email = mb_strtolower(trim($email));
        UserInvitation::where('email', $email)->whereNull('accepted_at')->delete();
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user || $user->isAdmin()) {
            return 'Convite revogado.';
        }
        $rest = array_values(array_diff((array) $user->modules, [self::MODULE]));
        if ($rest === []) {
            UserAccessService::block($actor, $user->id);

            return 'Acesso revogado: a conta só tinha Divisões e foi bloqueada.';
        }
        UserAccessService::setModules($actor, $user->id, $rest);

        return 'Módulo Divisões retirado; os outros módulos continuam.';
    }

    /** Remove a pessoa do grupo só se ela não tem despesa, parte ou acerto lá (não apaga histórico). */
    public function removeMember(?User $actor, DivisionMember $member): void
    {
        $actor = $this->assertAdmin($actor);
        if ($member->is_owner) {
            throw ValidationException::withMessages(['divisions' => 'O administrador não sai do grupo.']);
        }
        if ($reason = $this->removalBlocker($member)) {
            throw ValidationException::withMessages(['divisions' => $reason]);
        }
        $group = $member->group;
        $this->audit($group, $actor, 'member_removed', ['nome' => $member->name]);
        $member->delete();
    }

    /** Desativa (ou reativa) o participante: sai das novas despesas, histórico e saldos continuam. */
    public function setMemberActive(?User $actor, DivisionMember $member, bool $active): void
    {
        $actor = $this->assertAdmin($actor);
        if ($member->is_owner) {
            throw ValidationException::withMessages(['divisions' => 'O administrador não pode ser desativado.']);
        }
        $member->update(['active' => $active]);
        $this->audit($member->group, $actor, $active ? 'member_reactivated' : 'member_deactivated', ['nome' => $member->name]);
    }

    // ---------- Celular e cobrança (wa.me) ----------

    /** "(85) 99999-8888" → "+5585999998888". Vazio → null. Usa a mesma validação de telefone de Contatos. */
    public function normalizePhone(?string $raw): ?string
    {
        $digits = \App\Support\BrazilianDocuments::digits($raw);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        if (! \App\Support\BrazilianDocuments::isValidPhone($digits)) {
            throw ValidationException::withMessages(['phone' => 'Celular inválido. Use (99) 99999-9999.']);
        }

        return '+55'.$digits;
    }

    /** Admin altera de qualquer participante; o participante só o próprio. */
    public function setMemberPhone(User $actor, DivisionMember $member, ?string $raw): void
    {
        $own = $member->user_id !== null && (int) $member->user_id === (int) $actor->id;
        if (! $actor->isAdmin() && ! $own) {
            abort(403);
        }
        $phone = $this->normalizePhone($raw);
        // A mesma pessoa (mesmo e-mail) fica com o mesmo número em todos os grupos.
        $q = DivisionMember::query();
        $member->email ? $q->whereRaw('lower(email) = ?', [mb_strtolower($member->email)]) : $q->whereKey($member->id);
        $q->update(['phone' => $phone]);
        app(\App\Support\ProfileCompletionService::class)->syncContactPhone($member->email, $phone);
    }

    /** Só o administrador. A mesma pessoa (mesmo e-mail) fica com o mesmo nome em todos os grupos, no usuário e no contato. */
    public function setMemberName(User $actor, DivisionMember $member, string $name): void
    {
        $this->assertAdmin($actor);
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['memberNameDraft' => 'Informe um nome entre 2 e 120 caracteres.']);
        }
        $q = DivisionMember::query();
        $member->email ? $q->whereRaw('lower(email) = ?', [mb_strtolower($member->email)]) : $q->whereKey($member->id);
        $q->update(['name' => $name]);
        $user = $member->user_id ? User::find($member->user_id) : ($member->email ? User::whereRaw('lower(email) = ?', [mb_strtolower($member->email)])->first() : null);
        if ($user && ! $user->isAdmin()) {
            $user->forceFill(['name' => $name])->save();
            if ($user->contact_id) {
                \App\Domains\Contacts\Models\Contact::whereKey($user->contact_id)->update(['name' => $name]);
            }
        }
    }

    /** Pelo bloco de Configurações › Usuários (por e-mail). */
    public function setPersonPhone(?User $actor, string $email, ?string $raw): void
    {
        $this->assertAdmin($actor);
        $phone = $this->normalizePhone($raw);
        DivisionMember::whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->where('is_owner', false)->update(['phone' => $phone]);
        app(\App\Support\ProfileCompletionService::class)->syncContactPhone($email, $phone);
    }

    /** Mensagem educada e editável da cobrança. */
    public function chargeMessage(User $creditor, string $debtorName, int $cents, array $groupNames, string $link): string
    {
        $first = trim(strtok(trim($debtorName), ' ') ?: $debtorName);
        $groups = count($groupNames) === 1 ? 'do grupo "'.$groupNames[0].'"' : 'dos grupos "'.implode('", "', $groupNames).'"';

        return "Olá, {$first}! Tudo bem? Passando para lembrar do acerto {$groups} nas Divisões: "
            ."o saldo em aberto comigo está em R$ {$this->money($cents)}. "
            ."Os detalhes estão aqui: {$link} . Quando puder, me avise do pagamento. Obrigado! ".trim(strtok((string) $creditor->name, ' ') ?: '');
    }

    public function whatsappUrl(string $e164, string $text): string
    {
        return 'https://wa.me/'.ltrim($e164, '+').'?text='.rawurlencode($text);
    }

    /** Registra "cobrança aberta" no histórico (nada é enviado pelo sistema). */
    public function logCharge(?User $actor, DivisionMember $member, int $cents): void
    {
        $actor = $this->assertAdmin($actor);
        $this->audit($member->group, $actor, 'charge_opened', ['nome' => $member->name, 'valor' => 'R$ '.$this->money($cents), 'data' => now('America/Fortaleza')->format('d/m/Y H:i')]);
    }

    /** Pode remover? null = sim; senão, o motivo. */
    public function removalBlocker(DivisionMember $member): ?string
    {
        if ($member->is_owner) {
            return 'O administrador não sai do grupo.';
        }
        $parts = [];
        if (DivisionExpense::withTrashed()->where('paid_by_member_id', $member->id)->exists()) {
            $parts[] = 'pagou despesas';
        }
        if (\App\Domains\Divisions\Models\DivisionExpenseShare::where('member_id', $member->id)->exists()) {
            $parts[] = 'tem parte em despesas';
        }
        if (DivisionSettlement::withTrashed()->where(fn ($q) => $q->where('from_member_id', $member->id)->orWhere('to_member_id', $member->id))->exists()) {
            $parts[] = 'tem acertos';
        }

        return $parts ? $member->name.' '.implode(', ', $parts).' neste grupo. Para não apagar o histórico, não dá para remover; você pode desativar o participante.' : null;
    }

    /**
     * Extrato para o PDF: saldo anterior ao período, movimentos do período e saldo final com "quem paga quem".
     *
     * @return array{opening: array<int,int>, rows: list<array>, closing: array<int,int>, transfers: list<array>, totals: array}
     */
    public function statementReport(DivisionGroup $group, ?string $from, ?string $to): array
    {
        $all = $this->statement($group);
        $opening = [];
        $closing = [];
        $rows = [];
        $totals = ['expenses' => 0, 'settlements' => 0];
        foreach ($all as $r) {
            $d = \Illuminate\Support\Carbon::parse($r['date'])->toDateString();
            if ($to && $d > $to) {
                break;
            }
            $closing = $r['running'];
            if ($from && $d < $from) {
                $opening = $r['running'];

                continue;
            }
            $rows[] = $r;
            $totals[$r['kind'] === 'Despesa' ? 'expenses' : 'settlements'] += $r['cents'];
        }
        $net = $group->members()->pluck('id')->mapWithKeys(fn ($id) => [$id => (int) ($closing[$id] ?? 0)])->all();

        return ['opening' => $opening, 'rows' => $rows, 'closing' => $net, 'transfers' => $this->simplify($net), 'totals' => $totals];
    }

    /**
     * Pessoas ligadas a Divisões (usuários com o módulo, convites com o módulo e participantes dos grupos), por e-mail.
     *
     * @return list<array{email:string,name:string,user:?User,invitation:?UserInvitation,members:Collection}>
     */
    public function people(): array
    {
        $people = [];
        $put = function (string $email, string $name) use (&$people) {
            $k = mb_strtolower($email);
            $people[$k] ??= ['email' => $k, 'name' => $name, 'user' => null, 'invitation' => null, 'members' => collect()];
        };
        foreach (User::where('is_admin', false)->get() as $u) {
            if (in_array(self::MODULE, (array) $u->modules, true)) {
                $put($u->email, $u->name);
            }
        }
        foreach (UserInvitation::visible()->orderBy('id')->get() as $inv) {
            if (in_array(self::MODULE, (array) $inv->modules, true)) {
                $put($inv->email, $inv->name);
            }
        }
        foreach (DivisionMember::where('is_owner', false)->whereNotNull('email')->with('group')->get() as $m) {
            if ($m->group) {
                $put($m->email, $m->name);
            }
        }
        foreach ($people as $k => &$p) {
            $p['user'] = User::whereRaw('lower(email) = ?', [$k])->first();
            $p['invitation'] = UserInvitation::visible()->where('email', $k)->orderByDesc('id')->first();
            $p['members'] = DivisionMember::whereRaw('lower(email) = ?', [$k])->where('is_owner', false)->with('group')->get()->filter(fn ($m) => $m->group);
            if ($p['user']) {
                $p['name'] = $p['user']->name;
            }
        }

        return array_values($people);
    }

    // ---------- Divisão ----------

    /**
     * Calcula a parte de cada um. $inputs = [member_id => valor informado] (só quem participa).
     * equal: valor ignorado; percent: soma 100; amount: soma = total; shares: cotas > 0.
     *
     * @return array<int, array{input: float|null, cents: int}>
     */
    public function computeShares(int $totalCents, string $mode, array $inputs): array
    {
        if ($totalCents <= 0) {
            throw ValidationException::withMessages(['expenseAmount' => 'Informe um valor maior que zero.']);
        }
        if (! array_key_exists($mode, DivisionExpense::MODES)) {
            throw ValidationException::withMessages(['splitMode' => 'Forma de divisão inválida.']);
        }
        $weights = [];
        foreach ($inputs as $memberId => $raw) {
            $value = $mode === 'equal' ? 1.0 : $this->number($raw, $mode === 'amount');
            if ($value < 0) {
                throw ValidationException::withMessages(['splitRows' => 'Não use valores negativos na divisão.']);
            }
            if ($value > 0) {
                $weights[(int) $memberId] = $value;
            }
        }
        if ($weights === []) {
            throw ValidationException::withMessages(['splitRows' => 'Escolha pelo menos um participante na divisão.']);
        }
        if ($mode === 'percent' && abs(array_sum($weights) - 100) > 0.001) {
            throw ValidationException::withMessages(['splitRows' => 'Os percentuais somam '.$this->fmtNum(array_sum($weights)).'%. Precisam somar 100%.']);
        }
        if ($mode === 'amount') {
            $sum = (int) round(array_sum($weights) * 100);
            if ($sum !== $totalCents) {
                throw ValidationException::withMessages(['splitRows' => 'Os valores somam R$ '.$this->money($sum).'. Precisam somar R$ '.$this->money($totalCents).'.']);
            }
        }
        $cents = $this->allocate($totalCents, $weights);
        $out = [];
        foreach ($cents as $id => $c) {
            $out[$id] = ['input' => $mode === 'equal' ? null : $weights[$id], 'cents' => $c];
        }

        return $out;
    }

    /** Distribui centavos proporcionalmente aos pesos (maiores restos ganham o centavo que sobra). */
    public function allocate(int $totalCents, array $weights): array
    {
        $sum = array_sum($weights);
        $result = [];
        $remainders = [];
        foreach ($weights as $id => $w) {
            $exact = $totalCents * $w / $sum;
            $result[$id] = (int) floor($exact + 1e-9);
            $remainders[$id] = $exact - $result[$id];
        }
        $left = $totalCents - array_sum($result);
        arsort($remainders);
        foreach (array_keys($remainders) as $id) {
            if ($left <= 0) {
                break;
            }
            $result[$id]++;
            $left--;
        }

        return $result;
    }

    // ---------- Despesas ----------

    /**
     * @param array{description:string,category:string,date:string,amount:string,paid_by_member_id:int,split_mode:string,inputs:array,installments?:int,transaction_id?:int|null,notes?:string|null} $data
     * @return Collection<int, DivisionExpense>
     */
    public function saveExpense(User $actor, DivisionGroup $group, array $data, ?UploadedFile $receipt = null, ?DivisionExpense $editing = null): Collection
    {
        $this->assertView($actor, $group);
        if ($group->status !== 'approved') {
            throw ValidationException::withMessages(['expense' => 'O grupo ainda não foi aprovado.']);
        }
        $group->loadMissing('members');
        // Desativados não entram em despesa nova; na edição, quem já estava na despesa continua aceito.
        $memberIds = $group->members->filter(fn ($m) => $m->active)->pluck('id')->all();
        if ($editing) {
            $memberIds = array_values(array_unique(array_merge($memberIds, [(int) $editing->paid_by_member_id], $editing->shares()->pluck('member_id')->map(fn ($v) => (int) $v)->all())));
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 180) {
            throw ValidationException::withMessages(['expenseDescription' => 'Informe a descrição.']);
        }
        $category = (string) ($data['category'] ?? 'outros');
        if (! array_key_exists($category, DivisionExpense::categories())) {
            $category = 'outros';
        }
        try {
            $date = Carbon::parse((string) ($data['date'] ?? ''))->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['expenseDate' => 'Informe a data.']);
        }
        $payer = (int) ($data['paid_by_member_id'] ?? 0);
        if (! in_array($payer, $memberIds, true)) {
            throw ValidationException::withMessages(['paidBy' => 'Escolha quem pagou.']);
        }
        $inputs = array_filter((array) ($data['inputs'] ?? []), fn ($v, $k) => in_array((int) $k, $memberIds, true), ARRAY_FILTER_USE_BOTH);
        $total = (int) round(\App\Support\Money::toFloat($data['amount'] ?? '') * 100);
        $shares = $this->computeShares($total, (string) ($data['split_mode'] ?? ''), $inputs);
        $n = $editing ? 1 : max(1, min(60, (int) ($data['installments'] ?? 1)));

        $transactionId = ! empty($data['transaction_id']) ? (int) $data['transaction_id'] : null;
        // Como foi pago: account/card gera a saída no Financeiro; vazio = não lança (ou vincula a um lançamento existente).
        $method = in_array($data['payment_method'] ?? null, ['account', 'card'], true) ? $data['payment_method'] : null;
        $payAccountId = $method === 'account' ? (int) ($data['bank_account_id'] ?? 0) : null;
        $payCardId = $method === 'card' ? (int) ($data['credit_card_id'] ?? 0) : null;
        if ($method) {
            $owner = $group->ownerMember();
            if (! $actor->isAdmin() || ! $owner || $owner->id !== $payer) {
                throw ValidationException::withMessages(['paymentMethod' => 'Só dá para lançar no Financeiro uma despesa paga por você.']);
            }
            if ($method === 'account' && ! BankAccount::whereKey($payAccountId)->exists()) {
                throw ValidationException::withMessages(['paymentMethod' => 'Escolha a conta.']);
            }
            if ($method === 'card' && ! CreditCard::whereKey($payCardId)->exists()) {
                throw ValidationException::withMessages(['paymentMethod' => 'Escolha o cartão.']);
            }
            $transactionId = null;
        }
        $oldTxId = $editing?->transaction_id;
        if ($transactionId !== null) {
            $this->validateTransactionLink($actor, $group, $payer, $transactionId, $editing?->id);
        }

        $isMember = ! $actor->isAdmin();
        if ($editing && $isMember && ! ($editing->created_by === $actor->id && $editing->status === 'pending')) {
            abort(403);
        }
        $status = ($isMember && $group->requires_approval) ? 'pending' : 'approved';

        return DB::transaction(function () use ($actor, $group, $description, $category, $date, $payer, $shares, $total, $n, $transactionId, $status, $data, $receipt, $editing, $method, $payAccountId, $payCardId, $oldTxId) {
            $parts = $this->allocate($total, array_fill(1, $n, 1));
            $weights = array_map(fn ($s) => $s['cents'], $shares);
            $groupUuid = $n > 1 ? (string) Str::uuid() : null;
            $created = collect();
            $given = array_fill_keys(array_keys($weights), 0);
            foreach ($parts as $i => $partCents) {
                // A última parcela fecha a diferença: a soma das parcelas de cada pessoa é exatamente a parte dela.
                $partShares = $n === 1 ? $weights : ($i === $n ? array_map(fn ($w, $id) => $w - $given[$id], $weights, array_keys($weights)) : $this->allocate($partCents, $weights));
                if ($n > 1 && $i === $n) {
                    $partShares = array_combine(array_keys($weights), $partShares);
                }
                foreach ($partShares as $id => $c) {
                    $given[$id] += $c;
                }
                $attrs = [
                    'group_id' => $group->id, 'description' => $n > 1 ? "{$description} ({$i}/{$n})" : $description,
                    'category' => $category, 'date' => $date->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                    'amount' => $partCents / 100, 'paid_by_member_id' => $payer, 'split_mode' => (string) $data['split_mode'],
                    'status' => $editing ? ($actor->isAdmin() ? $editing->status : $status) : $status,
                    'transaction_id' => $i === 1 ? $transactionId : null,
                    'payment_method' => $method ?? ($i === 1 && $transactionId ? 'link' : null),
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                ];
                if ($n > 1) {
                    $attrs += ['installment_group_id' => $groupUuid, 'installment_number' => $i, 'installment_total' => $n];
                }
                if ($editing) {
                    $before = $this->snapshot($editing);
                    $editing->update($attrs);
                    $editing->shares()->delete();
                    $expense = $editing;
                } else {
                    $expense = DivisionExpense::create($attrs + ['created_by' => $actor->id]);
                }
                foreach ($partShares as $memberId => $cents) {
                    $expense->shares()->create(['member_id' => $memberId, 'input' => $shares[$memberId]['input'], 'amount' => $cents / 100]);
                }
                if ($receipt && $i === 1) {
                    $this->storeReceipt($expense, $receipt);
                }
                $expense->load('shares.member', 'payer');
                if ($editing) {
                    $this->audit($group, $actor, 'expense_updated', ['antes' => $before, 'depois' => $this->snapshot($expense)], $expense->id);
                } else {
                    $this->audit($group, $actor, 'expense_created', $this->snapshot($expense), $expense->id);
                }
                if ($method) {
                    $tx = $this->syncFinanceiro($expense, $method, $payAccountId, $payCardId);
                    $this->audit($group, $actor, 'expense_linked', ['lançamento' => $tx->id, 'gerado' => $method === 'card' ? 'cartão' : 'conta'], $expense->id);
                } elseif ($editing && $oldTxId && $oldTxId !== $transactionId) {
                    $this->dropGenerated($expense->id);
                }
                if ($transactionId && $i === 1) {
                    $this->audit($group, $actor, 'expense_linked', ['lançamento' => $transactionId], $expense->id);
                }
                $created->push($expense);
            }

            return $created;
        });
    }

    /** Cria/atualiza a saída "Divisões: ..." no Financeiro (conta ou fatura do cartão) vinculada à despesa. */
    public function syncFinanceiro(DivisionExpense $expense, string $method, ?int $accountId, ?int $cardId): Transaction
    {
        $tx = Transaction::where('source_type', 'division_expense')->where('source_id', $expense->id)->first();
        $oldInvoiceId = $tx?->credit_card_invoice_id;
        $attrs = [
            'type' => 'expense', 'date' => $expense->date->toDateString(), 'amount' => $expense->amount,
            'description' => mb_substr('Divisões: '.$expense->description, 0, 255), 'status' => 'settled',
            'category_id' => $this->financeCategoryId($expense->category), 'bank_account_id' => null,
            'credit_card_id' => null, 'credit_card_invoice_id' => null, 'source_type' => 'division_expense', 'source_id' => $expense->id,
        ];
        $invoice = null;
        if ($method === 'card') {
            $card = CreditCard::findOrFail($cardId);
            $invoice = app(InvoiceService::class)->findOrCreateForPurchase($card, $expense->date->copy());
            $attrs['credit_card_id'] = $card->id;
            $attrs['credit_card_invoice_id'] = $invoice->id;
        } else {
            $attrs['bank_account_id'] = $accountId;
        }
        if ($tx) {
            $tx->update($attrs);
        } else {
            $tx = Transaction::create($attrs);
        }
        $expense->forceFill(['transaction_id' => $tx->id, 'payment_method' => $method])->save();
        if ($invoice) {
            app(InvoiceService::class)->recalculateTotal($invoice);
        }
        if ($oldInvoiceId && $oldInvoiceId !== $invoice?->id && ($old = \App\Domains\Banking\Models\CreditCardInvoice::find($oldInvoiceId))) {
            app(InvoiceService::class)->recalculateTotal($old);
        }

        return $tx;
    }

    /** Apaga a saída gerada por esta despesa (só a gerada; lançamento vinculado manualmente fica). */
    private function dropGenerated(int $expenseId): void
    {
        $tx = Transaction::where('source_type', 'division_expense')->where('source_id', $expenseId)->first();
        if (! $tx) {
            return;
        }
        $invoice = $tx->credit_card_invoice_id ? \App\Domains\Banking\Models\CreditCardInvoice::find($tx->credit_card_invoice_id) : null;
        $tx->delete();
        if ($invoice) {
            app(InvoiceService::class)->recalculateTotal($invoice);
        }
    }

    private function financeCategoryId(string $key): ?int
    {
        $label = DivisionExpense::categories()[$key] ?? null;

        return $label ? Category::where('name', $label)->where('type', 'expense')->value('id') : null;
    }

    /** "+ Nova categoria": cria na hora (e a mesma categoria de saída no Financeiro, se for o Cassio). */
    public function createCategory(User $actor, string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', $label));
        if ($label === '' || mb_strlen($label) > 80) {
            throw ValidationException::withMessages(['newCategoryLabel' => 'Informe o nome da categoria (até 80 caracteres).']);
        }
        $existing = array_search(mb_strtolower($label), array_map('mb_strtolower', DivisionExpense::categories()), true);
        $key = $existing !== false ? (string) $existing : null;
        if ($key === null) {
            $base = mb_substr(Str::slug($label, '_'), 0, 50) ?: 'categoria';
            $key = $base;
            for ($i = 2; array_key_exists($key, DivisionExpense::categories()); $i++) {
                $key = $base.'_'.$i;
            }
            DivisionCategory::create(['key' => $key, 'label' => $label, 'created_by' => $actor->id]);
        }
        if ($actor->isAdmin()) {
            $name = DivisionExpense::categories()[$key];
            Category::firstOrCreate(['name' => $name, 'type' => 'expense'], ['status' => true]);
        }

        return $key;
    }

    private function snapshot(DivisionExpense $e): array
    {
        $e->loadMissing('shares.member', 'payer');

        return [
            'descrição' => $e->description, 'valor' => 'R$ '.$this->money((int) round($e->amount * 100)), 'data' => $e->date?->format('d/m/Y'),
            'pagou' => $e->payer?->name, 'divisão' => DivisionExpense::MODES[$e->split_mode] ?? $e->split_mode,
            'partes' => $e->shares->mapWithKeys(fn ($s) => [$s->member?->name ?? '#'.$s->member_id => 'R$ '.$this->money((int) round($s->amount * 100))])->all(),
        ];
    }

    private function storeReceipt(DivisionExpense $expense, UploadedFile $file): void
    {
        if ($expense->receipt_path) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
        $path = $file->storeAs('divisoes/'.$expense->group_id, Str::uuid().'.'.$file->getClientOriginalExtension(), 'local');
        $expense->update(['receipt_path' => $path, 'receipt_original_name' => $file->getClientOriginalName(), 'receipt_mime' => $file->getMimeType()]);
    }

    /** Só o Cassio vincula, só quando ele pagou, e o lançamento não pode estar em outra despesa. */
    private function validateTransactionLink(User $actor, DivisionGroup $group, int $payerId, int $transactionId, ?int $ignoreExpenseId): void
    {
        $owner = $group->ownerMember();
        if (! $actor->isAdmin() || ! $owner || $owner->id !== $payerId) {
            throw ValidationException::withMessages(['transactionId' => 'Só dá para vincular ao Financeiro uma despesa paga por você.']);
        }
        $tx = Transaction::find($transactionId);
        if (! $tx || $tx->type !== 'expense') {
            throw ValidationException::withMessages(['transactionId' => 'Escolha uma saída do Financeiro ou do cartão.']);
        }
        $used = DivisionExpense::where('transaction_id', $transactionId)->when($ignoreExpenseId, fn ($q) => $q->where('id', '!=', $ignoreExpenseId))->exists();
        if ($used) {
            throw ValidationException::withMessages(['transactionId' => 'Este lançamento já está vinculado a outra despesa.']);
        }
    }

    public function decideExpense(?User $actor, DivisionExpense $expense, bool $approve): void
    {
        $actor = $this->assertAdmin($actor);
        if ($expense->status !== 'pending') {
            throw ValidationException::withMessages(['expense' => 'Esta despesa não está aguardando aprovação.']);
        }
        $expense->update(['status' => $approve ? 'approved' : 'rejected']);
        $this->audit($expense->group, $actor, $approve ? 'expense_approved' : 'expense_rejected', [], $expense->id);
    }

    public function deleteExpense(User $actor, DivisionExpense $expense): void
    {
        $this->assertView($actor, $expense->group);
        abort_unless($actor->isAdmin() || ($expense->created_by === $actor->id && $expense->status === 'pending'), 403);
        $this->audit($expense->group, $actor, 'expense_deleted', $this->snapshot($expense), $expense->id);
        DB::transaction(function () use ($expense) {
            $this->dropGenerated($expense->id);
            $expense->delete();
        });
    }

    // ---------- Acertos ----------

    public function settle(User $actor, DivisionGroup $group, array $data): DivisionSettlement
    {
        $this->assertView($actor, $group);
        $group->loadMissing('members');
        $from = $group->members->firstWhere('id', (int) ($data['from_member_id'] ?? 0));
        $to = $group->members->firstWhere('id', (int) ($data['to_member_id'] ?? 0));
        if (! $from || ! $to || $from->id === $to->id || $from->isPending() || $to->isPending()) {
            throw ValidationException::withMessages(['settleFrom' => 'Escolha quem pagou e quem recebeu (pessoas diferentes).']);
        }
        if (! $actor->isAdmin()) {
            $me = $group->memberFor($actor);
            abort_unless($me && in_array($me->id, [$from->id, $to->id], true), 403);
        }
        $cents = (int) round(\App\Support\Money::toFloat($data['amount'] ?? '') * 100);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['settleAmount' => 'Informe um valor maior que zero.']);
        }
        try {
            $date = Carbon::parse((string) ($data['date'] ?? ''))->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['settleDate' => 'Informe a data.']);
        }
        $ownerInvolved = $from->is_owner || $to->is_owner;
        $accountId = ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        if ($actor->isAdmin() && $ownerInvolved && ! $accountId) {
            throw ValidationException::withMessages(['settleAccount' => 'Escolha a conta do Financeiro.']);
        }
        if ($accountId !== null && (! $actor->isAdmin() || ! $ownerInvolved || ! BankAccount::whereKey($accountId)->exists())) {
            throw ValidationException::withMessages(['settleAccount' => 'Conta inválida.']);
        }

        return DB::transaction(function () use ($actor, $group, $from, $to, $cents, $date, $accountId, $data) {
            $settlement = DivisionSettlement::create(['group_id' => $group->id, 'from_member_id' => $from->id, 'to_member_id' => $to->id,
                'amount' => $cents / 100, 'date' => $date, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'created_by' => $actor->id]);
            if ($accountId) {
                $other = $from->is_owner ? $to : $from;
                $tx = Transaction::create([
                    'type' => $from->is_owner ? 'expense' : 'income', 'date' => $date, 'amount' => $cents / 100,
                    'description' => 'Divisões · '.$group->name.' · acerto com '.$other->name,
                    'notes' => $settlement->notes, 'status' => 'settled', 'bank_account_id' => $accountId,
                    'source_type' => 'division_settlement', 'source_id' => $settlement->id,
                ]);
                $settlement->update(['transaction_id' => $tx->id]);
            }
            $this->audit($group, $actor, 'settlement_created', ['de' => $from->name, 'para' => $to->name, 'valor' => 'R$ '.$this->money($cents), 'financeiro' => $settlement->transaction_id], null, $settlement->id);

            return $settlement;
        });
    }

    public function deleteSettlement(?User $actor, DivisionSettlement $settlement): void
    {
        $actor = $this->assertAdmin($actor);
        DB::transaction(function () use ($actor, $settlement) {
            $group = DivisionGroup::findOrFail($settlement->group_id);
            if ($settlement->transaction_id) {
                Transaction::whereKey($settlement->transaction_id)->first()?->delete();
            }
            $this->audit($group, $actor, 'settlement_deleted', ['valor' => 'R$ '.$this->money((int) round($settlement->amount * 100))], null, $settlement->id);
            $settlement->delete();
        });
    }

    // ---------- Saldos ----------

    /** Saldo líquido por participante em centavos (positivo = tem a receber). */
    public function netBalances(DivisionGroup $group): array
    {
        $net = $group->members()->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all();
        $expenses = $group->expenses()->where('status', 'approved')->with('shares')->get();
        foreach ($expenses as $e) {
            $net[$e->paid_by_member_id] = ($net[$e->paid_by_member_id] ?? 0) + (int) round($e->amount * 100);
            foreach ($e->shares as $s) {
                $net[$s->member_id] = ($net[$s->member_id] ?? 0) - (int) round($s->amount * 100);
            }
        }
        foreach ($group->settlements()->get() as $s) {
            $c = (int) round($s->amount * 100);
            $net[$s->from_member_id] = ($net[$s->from_member_id] ?? 0) + $c;
            $net[$s->to_member_id] = ($net[$s->to_member_id] ?? 0) - $c;
        }

        return $net;
    }

    /**
     * Quem deve a quem, proporcional: cada devedor deve a cada credor na proporção do saldo credor
     * de cada um (dívida_ij = devido_i × crédito_j / total_créditos). Acertos já entram no saldo,
     * então um pagamento a um credor reequilibra a divisão entre todos. Centavos fecham exatos
     * por linha (devedor) e por coluna (credor).
     *
     * @return list<array{from:int,to:int,cents:int}>
     */
    public function simplify(array $net): array
    {
        $debtors = [];
        $creditors = [];
        foreach ($net as $id => $c) {
            if ($c < 0) {
                $debtors[$id] = -$c;
            } elseif ($c > 0) {
                $creditors[$id] = $c;
            }
        }
        $totalCredit = array_sum($creditors);
        $totalDebt = array_sum($debtors);
        if (! $debtors || ! $creditors) {
            return [];
        }
        // Se créditos e débitos não batem (não deveria), a base é o menor dos dois.
        $base = min($totalCredit, $totalDebt);
        $m = [];
        foreach ($debtors as $d => $due) {
            $rowTotal = intdiv($due * $base, $totalDebt);
            $alloc = [];
            $rem = [];
            foreach ($creditors as $c => $cr) {
                $exact = $rowTotal * $cr / $totalCredit;
                $alloc[$c] = (int) floor($exact);
                $rem[$c] = $exact - $alloc[$c];
            }
            $left = $rowTotal - array_sum($alloc);
            arsort($rem);
            foreach (array_keys($rem) as $c) {
                if ($left <= 0) {
                    break;
                }
                $alloc[$c]++;
                $left--;
            }
            $m[$d] = $alloc;
        }
        // Ajuste de centavos por coluna: cada credor recebe exatamente seu crédito (dentro da base).
        $target = [];
        $acc = 0;
        $i = 0;
        foreach ($creditors as $c => $cr) {
            $i++;
            $target[$c] = $i === count($creditors) ? $base - $acc : intdiv($cr * $base, $totalCredit);
            $acc += $target[$c];
        }
        for ($guard = 0; $guard < 10000; $guard++) {
            $col = [];
            foreach ($creditors as $c => $cr) {
                $col[$c] = array_sum(array_column($m, $c)) - $target[$c];
            }
            $over = array_keys(array_filter($col, fn ($v) => $v > 0));
            $under = array_keys(array_filter($col, fn ($v) => $v < 0));
            if (! $over || ! $under) {
                break;
            }
            $moved = false;
            foreach ($m as $d => $row) {
                if ($row[$over[0]] > 0) {
                    $m[$d][$over[0]]--;
                    $m[$d][$under[0]]++;
                    $moved = true;
                    break;
                }
            }
            if (! $moved) {
                break;
            }
        }
        $out = [];
        foreach ($m as $d => $row) {
            foreach ($row as $c => $cents) {
                if ($cents > 0) {
                    $out[] = ['from' => $d, 'to' => $c, 'cents' => $cents];
                }
            }
        }

        return $out;
    }

    /**
     * Saldo geral de cada pessoa em relação a quem está vendo, somando os grupos visíveis.
     * Para o Cassio: positivo = a pessoa deve a ele. Para o participante: o saldo dele com cada pessoa.
     *
     * @return list<array{name:string,cents:int}>
     */
    public function overall(User $viewer): array
    {
        $groups = DivisionGroup::visibleTo($viewer)->where('status', 'approved')->with('members')->get();
        $people = [];
        foreach ($groups as $group) {
            $me = $viewer->isAdmin() ? $group->ownerMember() : $group->memberFor($viewer);
            if (! $me) {
                continue;
            }
            foreach ($this->simplify($this->netBalances($group)) as $t) {
                if ($t['to'] === $me->id) {
                    $other = $group->members->firstWhere('id', $t['from']);
                    $sign = 1;
                } elseif ($t['from'] === $me->id) {
                    $other = $group->members->firstWhere('id', $t['to']);
                    $sign = -1;
                } else {
                    continue;
                }
                $key = $other->personKey();
                $people[$key] ??= ['key' => $key, 'name' => $other->name, 'cents' => 0, 'phone' => null, 'groups' => []];
                $people[$key]['cents'] += $sign * $t['cents'];
                $people[$key]['phone'] ??= $other->phone;
                $people[$key]['groups'][$group->id] = ['name' => $group->name, 'member_id' => $other->id, 'cents' => ($people[$key]['groups'][$group->id]['cents'] ?? 0) + $sign * $t['cents']];
            }
        }

        return array_values(array_filter($people, fn ($p) => $p['cents'] !== 0));
    }

    /** Extrato do grupo: despesas aprovadas e acertos em ordem de data, com o efeito em cada participante. */
    public function statement(DivisionGroup $group): array
    {
        $rows = [];
        foreach ($group->expenses()->where('status', 'approved')->with('shares', 'payer')->get() as $e) {
            $effect = [$e->paid_by_member_id => (int) round($e->amount * 100)];
            foreach ($e->shares as $s) {
                $effect[$s->member_id] = ($effect[$s->member_id] ?? 0) - (int) round($s->amount * 100);
            }
            $rows[] = ['date' => $e->date, 'kind' => 'Despesa', 'description' => $e->description.' · pago por '.$e->payer?->name, 'cents' => (int) round($e->amount * 100), 'effect' => $effect, 'sort' => 'e'.$e->id];
        }
        foreach ($group->settlements()->with('from', 'to')->get() as $s) {
            $c = (int) round($s->amount * 100);
            $rows[] = ['date' => $s->date, 'kind' => 'Acerto', 'description' => $s->from?->name.' pagou '.$s->to?->name, 'cents' => $c, 'effect' => [$s->from_member_id => $c, $s->to_member_id => -$c], 'sort' => 's'.$s->id];
        }
        usort($rows, fn ($a, $b) => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);
        $running = [];
        foreach ($rows as &$r) {
            foreach ($r['effect'] as $id => $c) {
                $running[$id] = ($running[$id] ?? 0) + $c;
            }
            $r['running'] = $running;
        }

        return $rows;
    }

    // ---------- Utilidades ----------

    private function number(mixed $raw, bool $money): float
    {
        if ($money) {
            return \App\Support\Money::toFloat($raw);
        }
        $s = str_replace(' ', '', (string) $raw);
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    public function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    private function fmtNum(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
    }
}
