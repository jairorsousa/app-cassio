<?php

namespace App\Domains\Contacts\Services;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Contacts\Models\IdentityChangeRequest;
use App\Models\User;
use App\Support\BrazilianDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Identidade da pessoa: CPF/CNPJ único e e-mails (vários) no Contato.
 * - CPF já cadastrado só muda com aprovação do administrador (o admin muda direto).
 * - E-mail novo entra direto; excluir e-mail fica pendente até o admin aprovar.
 */
class PersonIdentityService
{
    public static function normEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /** @return list<string> e-mails do contato (principal primeiro), minúsculos e sem repetição */
    public function emailsOf(Contact $contact): array
    {
        $all = array_merge([(string) $contact->email], (array) $contact->emails);

        return array_values(array_unique(array_filter(array_map([self::class, 'normEmail'], $all))));
    }

    /** E-mails de login dos usuários ligados ao contato. */
    public function loginEmailsOf(Contact $contact): array
    {
        return User::where('contact_id', $contact->id)->pluck('email')->map(fn ($e) => self::normEmail($e))->filter()->values()->all();
    }

    /** Outro contato (não excluído) com o mesmo CPF/CNPJ. */
    public function documentOwner(?string $document, ?int $exceptContactId = null): ?Contact
    {
        $digits = BrazilianDocuments::digits($document);
        if ($digits === '') {
            return null;
        }

        return Contact::withTests()->whereNotNull('document')->when($exceptContactId, fn ($q) => $q->whereKeyNot($exceptContactId))->get()
            ->first(fn (Contact $c) => BrazilianDocuments::digits($c->document) === $digits);
    }

    /** Nome de quem já usa o e-mail (outro contato, ou usuário ligado a outro contato). */
    public function emailOwnerName(?string $email, ?int $exceptContactId = null): ?string
    {
        $email = self::normEmail($email);
        if ($email === '') {
            return null;
        }
        $contact = Contact::withTests()->when($exceptContactId, fn ($q) => $q->whereKeyNot($exceptContactId))->get()
            ->first(fn (Contact $c) => in_array($email, $this->emailsOf($c), true));
        if ($contact) {
            return $contact->name;
        }
        $user = User::whereRaw('lower(email) = ?', [$email])->whereNotNull('contact_id')
            ->when($exceptContactId, fn ($q) => $q->where('contact_id', '!=', $exceptContactId))->first();

        return $user?->name;
    }

    /** Regras de validação reaproveitadas nos formulários de Contatos. */
    public function uniqueDocumentRule(?int $contactId): \Closure
    {
        return function ($attribute, $value, $fail) use ($contactId) {
            if (trim((string) $value) !== '' && ($owner = $this->documentOwner($value, $contactId))) {
                $fail('Este CPF/CNPJ já está no cadastro de '.$owner->name.'. Cada pessoa tem um CPF só.');
            }
        };
    }

    public function uniqueEmailRule(?int $contactId): \Closure
    {
        return function ($attribute, $value, $fail) use ($contactId) {
            if (trim((string) $value) !== '' && ($owner = $this->emailOwnerName($value, $contactId))) {
                $fail('Este e-mail já pertence a '.$owner.'.');
            }
        };
    }

    /**
     * Edição de contato existente: quem não é admin não troca CPF já cadastrado nem exclui e-mail;
     * vira pedido pendente e o valor antigo é mantido.
     *
     * @return array{0: array, 1: list<string>} [dados a gravar, avisos]
     */
    public function guardContactEdit(Contact $contact, array $data, ?User $actor): array
    {
        if ($actor?->isAdmin()) {
            return [$data, []];
        }
        $notices = [];

        if (array_key_exists('document', $data)) {
            $old = BrazilianDocuments::digits($contact->document);
            $new = BrazilianDocuments::digits($data['document'] ?? '');
            if ($old !== '' && $new !== $old) {
                $data['document'] = $contact->document;
                if ($new === '') {
                    $notices[] = 'O CPF/CNPJ cadastrado não pode ser apagado. Se estiver errado, informe o correto: a troca vai para aprovação.';
                } else {
                    $this->requestDocumentChange($contact, $new, $actor);
                    $notices[] = 'A troca do CPF/CNPJ foi enviada para aprovação do administrador. Até lá, vale o CPF antigo.';
                }
            }
        }

        if (array_key_exists('emails', $data)) {
            $oldEmails = $this->emailsOf($contact);
            $newEmails = array_values(array_unique(array_filter(array_map([self::class, 'normEmail'], (array) $data['emails']))));
            $removed = array_values(array_diff($oldEmails, $newEmails));
            foreach ($removed as $email) {
                try {
                    $this->requestEmailRemoval($contact, $email, $actor);
                    $notices[] = 'A exclusão do e-mail '.$email.' foi enviada para aprovação do administrador.';
                } catch (ValidationException $e) {
                    $notices[] = collect($e->errors())->flatten()->first();
                }
            }
            // Mantém os e-mails antigos (na ordem de antes) até a aprovação; os novos entram no fim.
            $kept = array_values(array_unique(array_merge(array_values(array_intersect($oldEmails, array_merge($newEmails, $removed))), $newEmails)));
            $data['emails'] = $kept;
            $data['email'] = $kept[0] ?? null;
        }

        return [$data, $notices];
    }

    public function requestDocumentChange(Contact $contact, string $newDocument, ?User $actor): IdentityChangeRequest
    {
        $digits = BrazilianDocuments::digits($newDocument);
        if (! BrazilianDocuments::isValidCpfOrCnpj($digits)) {
            throw ValidationException::withMessages(['document' => 'Informe um CPF ou CNPJ válido.']);
        }
        if ($digits === BrazilianDocuments::digits($contact->document)) {
            throw ValidationException::withMessages(['document' => 'Este já é o CPF/CNPJ do cadastro.']);
        }
        if ($owner = $this->documentOwner($digits, $contact->id)) {
            throw ValidationException::withMessages(['document' => 'Este CPF/CNPJ já está no cadastro de outra pessoa. Fale com o administrador.']);
        }
        $formatted = BrazilianDocuments::formatCpfCnpj($digits);

        return DB::transaction(function () use ($contact, $formatted, $actor) {
            $pending = IdentityChangeRequest::pending()->where('contact_id', $contact->id)->where('type', IdentityChangeRequest::TYPE_DOCUMENT)->lockForUpdate()->first();
            if ($pending) {
                $pending->update(['new_value' => $formatted, 'old_value' => $contact->document, 'requested_by' => $actor?->id]);

                return $pending;
            }

            return IdentityChangeRequest::create([
                'contact_id' => $contact->id, 'requested_by' => $actor?->id, 'type' => IdentityChangeRequest::TYPE_DOCUMENT,
                'old_value' => $contact->document, 'new_value' => $formatted, 'status' => 'pending',
            ]);
        });
    }

    public function requestEmailRemoval(Contact $contact, string $email, ?User $actor): IdentityChangeRequest
    {
        $email = self::normEmail($email);
        if (! in_array($email, $this->emailsOf($contact), true)) {
            throw ValidationException::withMessages(['email' => 'Este e-mail não está no cadastro.']);
        }
        if (in_array($email, $this->loginEmailsOf($contact), true)) {
            throw ValidationException::withMessages(['email' => 'O e-mail '.$email.' é o de acesso ao sistema e não pode ser excluído por aqui. Fale com o administrador.']);
        }
        $pending = IdentityChangeRequest::pending()->where('contact_id', $contact->id)->where('type', IdentityChangeRequest::TYPE_EMAIL_REMOVE)->where('old_value', $email)->first();

        return $pending ?: IdentityChangeRequest::create([
            'contact_id' => $contact->id, 'requested_by' => $actor?->id, 'type' => IdentityChangeRequest::TYPE_EMAIL_REMOVE,
            'old_value' => $email, 'new_value' => null, 'status' => 'pending',
        ]);
    }

    /** E-mail novo entra direto (sem aprovação), desde que não seja de outra pessoa. */
    public function addEmail(Contact $contact, string $email): void
    {
        $email = self::normEmail($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
            throw ValidationException::withMessages(['newEmail' => 'Informe um e-mail válido.']);
        }
        if (in_array($email, $this->emailsOf($contact), true)) {
            throw ValidationException::withMessages(['newEmail' => 'Este e-mail já está no seu cadastro.']);
        }
        if ($this->emailOwnerName($email, $contact->id)) {
            throw ValidationException::withMessages(['newEmail' => 'Este e-mail já pertence a outra pessoa no cadastro.']);
        }
        $emails = array_merge($this->emailsOf($contact), [$email]);
        $contact->update(['emails' => $emails, 'email' => $emails[0]]);
    }

    /**
     * Só o admin troca o e-mail de login. O novo não pode ser de outra pessoa; o antigo continua na lista do contato.
     * Nenhum e-mail é enviado. O vínculo Google é zerado para o novo e-mail Google se ligar no próximo login.
     */
    public function changeLoginEmail(User $user, string $newEmail, User $admin): void
    {
        $this->assertAdmin($admin);
        $new = self::normEmail($newEmail);
        $old = self::normEmail($user->email);
        if (! filter_var($new, FILTER_VALIDATE_EMAIL) || mb_strlen($new) > 200) {
            throw ValidationException::withMessages(['newLoginEmail' => 'Informe um e-mail válido.']);
        }
        if ($new === $old) {
            throw ValidationException::withMessages(['newLoginEmail' => 'Este já é o e-mail de acesso.']);
        }
        if (User::whereRaw('lower(email) = ?', [$new])->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['newLoginEmail' => 'Este e-mail já é o acesso de outro usuário.']);
        }
        if ($owner = $this->emailOwnerName($new, $user->contact_id)) {
            throw ValidationException::withMessages(['newLoginEmail' => 'Este e-mail pertence a '.$owner.'.']);
        }

        DB::transaction(function () use ($user, $new, $old, $admin) {
            $user->forceFill(['email' => $new, 'google_sub' => null])->save();
            $contact = $user->contact_id ? Contact::withTests()->withTrashed()->find($user->contact_id) : null;
            if ($contact) {
                $emails = array_values(array_unique(array_merge([$new], $this->emailsOf($contact), [$old])));
                $contact->update(['emails' => $emails, 'email' => $new]);
            }
            // Participações do Divisões desta conta seguem o novo e-mail (o vínculo principal é o user_id).
            \App\Domains\Divisions\Models\DivisionMember::where('user_id', $user->id)->whereRaw('lower(email) = ?', [$old])->update(['email' => $new]);
            DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [$old])->delete();
            \App\Support\AuditLogger::write('login_email_changed', 'User', $user->id, $user->name, ['email' => $old], ['email' => $new], $admin);
        });
    }

    public function approve(IdentityChangeRequest $request, User $admin): void
    {
        $this->assertAdmin($admin);
        DB::transaction(function () use ($request, $admin) {
            $request = IdentityChangeRequest::lockForUpdate()->findOrFail($request->id);
            if (! $request->isPending()) {
                throw ValidationException::withMessages(['identity' => 'Este pedido já foi decidido.']);
            }
            $contact = Contact::withTests()->findOrFail($request->contact_id);
            if ($request->type === IdentityChangeRequest::TYPE_DOCUMENT) {
                if (! BrazilianDocuments::isValidCpfOrCnpj($request->new_value)) {
                    throw ValidationException::withMessages(['identity' => 'O CPF/CNPJ pedido é inválido.']);
                }
                if ($owner = $this->documentOwner($request->new_value, $contact->id)) {
                    throw ValidationException::withMessages(['identity' => 'O CPF/CNPJ pedido já está no cadastro de '.$owner->name.'. Recuse o pedido ou corrija o outro cadastro antes.']);
                }
                $contact->update(['document' => BrazilianDocuments::formatCpfCnpj($request->new_value)]);
            } else {
                $email = self::normEmail($request->old_value);
                if (in_array($email, $this->loginEmailsOf($contact), true)) {
                    throw ValidationException::withMessages(['identity' => 'Este e-mail é o de acesso ao sistema; troque o acesso antes de excluí-lo.']);
                }
                $emails = array_values(array_filter($this->emailsOf($contact), fn ($e) => $e !== $email));
                $contact->update(['emails' => $emails, 'email' => $emails[0] ?? null]);
            }
            $request->update(['status' => 'approved', 'decided_by' => $admin->id, 'decided_at' => now()]);
        });
    }

    public function reject(IdentityChangeRequest $request, User $admin, ?string $note = null): void
    {
        $this->assertAdmin($admin);
        if (! $request->fresh()->isPending()) {
            throw ValidationException::withMessages(['identity' => 'Este pedido já foi decidido.']);
        }
        $request->update(['status' => 'rejected', 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr(trim($note), 0, 500) : null]);
    }

    /** O próprio solicitante pode desistir do pedido enquanto estiver pendente. */
    public function cancel(IdentityChangeRequest $request, User $user): void
    {
        if (! $request->isPending() || ((int) $request->requested_by !== (int) $user->id && ! $user->isAdmin())) {
            throw ValidationException::withMessages(['identity' => 'Não é possível cancelar este pedido.']);
        }
        $request->update(['status' => 'cancelled', 'decided_by' => $user->id, 'decided_at' => now()]);
    }

    public static function pendingCount(): int
    {
        try {
            return IdentityChangeRequest::pending()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function assertAdmin(?User $user): void
    {
        if (! $user?->isAdmin()) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Somente o administrador decide estes pedidos.');
        }
    }
}
