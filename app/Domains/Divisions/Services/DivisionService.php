<?php

namespace App\Domains\Divisions\Services;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Divisions\Models\DivisionAudit;
use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Models\DivisionMember;
use App\Domains\Divisions\Models\DivisionSettlement;
use App\Models\User;
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
        DivisionMember::whereNull('user_id')->whereNotNull('email')
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->update(['user_id' => $user->id]);
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

    public function addMember(?User $actor, DivisionGroup $group, string $name, ?string $email): DivisionMember
    {
        $actor = $this->assertAdmin($actor);
        $name = trim($name);
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['memberName' => 'Informe o nome do participante.']);
        }
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['memberEmail' => 'E-mail inválido.']);
        }
        if ($email !== null && $group->members()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['memberEmail' => 'Este e-mail já participa do grupo.']);
        }
        $user = $email ? User::whereRaw('lower(email) = ?', [$email])->first() : null;
        $member = $group->members()->create(['name' => $name, 'email' => $email, 'user_id' => $user?->id]);
        $this->audit($group, $actor, 'member_added', ['nome' => $name]);

        return $member;
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
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($member->email)])->first();
        if ($user) {
            $member->update(['user_id' => $user->id]);
            if (! $user->isAdmin() && ! in_array(self::MODULE, (array) $user->modules, true)) {
                UserAccessService::setModules($actor, $user->id, array_merge((array) $user->modules, [self::MODULE]));
            }

            return null;
        }
        [, $token] = UserAccessService::invite($actor, $member->name, $member->email, [self::MODULE]);
        $this->audit($member->group, $actor, 'member_invited', ['nome' => $member->name]);

        return route('invite.accept', ['token' => $token]);
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
        $memberIds = $group->members->pluck('id')->all();
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 180) {
            throw ValidationException::withMessages(['expenseDescription' => 'Informe a descrição.']);
        }
        $category = (string) ($data['category'] ?? 'outros');
        if (! array_key_exists($category, DivisionExpense::CATEGORIES)) {
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
        if ($transactionId !== null) {
            $this->validateTransactionLink($actor, $group, $payer, $transactionId, $editing?->id);
        }

        $isMember = ! $actor->isAdmin();
        if ($editing && $isMember && ! ($editing->created_by === $actor->id && $editing->status === 'pending')) {
            abort(403);
        }
        $status = ($isMember && $group->requires_approval) ? 'pending' : 'approved';

        return DB::transaction(function () use ($actor, $group, $description, $category, $date, $payer, $shares, $total, $n, $transactionId, $status, $data, $receipt, $editing) {
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
                if ($transactionId && $i === 1) {
                    $this->audit($group, $actor, 'expense_linked', ['lançamento' => $transactionId], $expense->id);
                }
                $created->push($expense);
            }

            return $created;
        });
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
        $expense->delete();
    }

    // ---------- Acertos ----------

    public function settle(User $actor, DivisionGroup $group, array $data): DivisionSettlement
    {
        $this->assertView($actor, $group);
        $group->loadMissing('members');
        $from = $group->members->firstWhere('id', (int) ($data['from_member_id'] ?? 0));
        $to = $group->members->firstWhere('id', (int) ($data['to_member_id'] ?? 0));
        if (! $from || ! $to || $from->id === $to->id) {
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
     * Dívidas simplificadas: o menor número de pagamentos para zerar todo mundo.
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
        $out = [];
        while ($debtors && $creditors) {
            arsort($debtors);
            arsort($creditors);
            $d = array_key_first($debtors);
            $c = array_key_first($creditors);
            $amount = min($debtors[$d], $creditors[$c]);
            $out[] = ['from' => $d, 'to' => $c, 'cents' => $amount];
            $debtors[$d] -= $amount;
            $creditors[$c] -= $amount;
            if ($debtors[$d] === 0) {
                unset($debtors[$d]);
            }
            if ($creditors[$c] === 0) {
                unset($creditors[$c]);
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
                $people[$key] ??= ['name' => $other->name, 'cents' => 0];
                $people[$key]['cents'] += $sign * $t['cents'];
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
