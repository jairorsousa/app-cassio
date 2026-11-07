<?php

namespace App\Support;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Models\DivisionMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Cadastro do convidado: cria ou atualiza o Contato (sem duplicar), liga ao usuário e sincroniza o celular de Divisões. */
class ProfileCompletionService
{
    /** Papel do contato conforme os módulos (os papéis existentes em Contatos). */
    public function roleFor(User $user): string
    {
        $modules = (array) $user->modules;

        return match (true) {
            in_array('corretores', $modules, true) && count($modules) === 1 => 'corretor',
            default => 'pessoal', // Divisões (participante/familiar) e demais convidados
        };
    }

    public function findContact(User $user, ?string $document): ?Contact
    {
        if ($user->contact_id && ($c = Contact::find($user->contact_id))) {
            return $c;
        }
        $email = mb_strtolower(trim((string) $user->email));
        $digits = BrazilianDocuments::digits($document);
        $byEmail = Contact::whereRaw('lower(email) = ?', [$email])->first();
        if (! $byEmail) {
            $byEmail = Contact::whereNotNull('emails')->get()->first(fn (Contact $c) => in_array($email, array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) $c->emails), true));
        }
        if ($byEmail) {
            return $byEmail;
        }
        if ($digits !== '') {
            return Contact::whereNotNull('document')->get()->first(fn (Contact $c) => BrazilianDocuments::digits($c->document) === $digits);
        }

        return null;
    }

    /** Prefill: nome e e-mail do usuário; celular do participante de Divisões ou do Contato já ligado. */
    public function prefill(User $user): array
    {
        $contact = $this->findContact($user, null);
        $memberPhone = DivisionMember::whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)])->whereNotNull('phone')->value('phone');
        $invitePhone = \App\Models\UserInvitation::whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)])->whereNotNull('phone')->orderByDesc('id')->value('phone');
        $e164 = $memberPhone ?: $invitePhone;
        $phone = $e164 ? BrazilianDocuments::formatPhone(substr($e164, 3)) : (string) ($contact?->phone ?? '');

        return [
            'name' => (string) ($contact?->name ?: $user->name),
            'email' => (string) $user->email,
            'phone' => $phone,
            'document' => (string) ($contact?->document ?? ''),
            'birth_date' => $contact?->birth_date?->toDateString(),
            'zip_code' => (string) ($contact?->zip_code ?? ''),
            'street' => (string) ($contact?->street ?? ''),
            'number' => (string) ($contact?->number ?? ''),
            'complement' => (string) ($contact?->complement ?? ''),
            'neighborhood' => (string) ($contact?->neighborhood ?? ''),
            'city' => (string) ($contact?->city ?? ''),
            'state' => (string) ($contact?->state ?? ''),
            'pix_key_type' => (string) ($contact?->pix_key_type ?? ''),
            'pix_key' => (string) ($contact?->pix_key ?? ''),
        ];
    }

    public function complete(User $user, array $data): Contact
    {
        return DB::transaction(function () use ($user, $data) {
            $contact = $this->findContact($user, $data['document'] ?? null);
            $email = mb_strtolower(trim((string) $user->email));
            $phone = BrazilianDocuments::formatPhone($data['phone']);
            $line = trim(collect([$data['street'] ?? '', $data['number'] ?? ''])->filter()->implode(', '));
            $details = trim(collect([$data['neighborhood'] ?? '', $data['complement'] ?? '', $data['city'] ?? '', $data['state'] ?? '', $data['zip_code'] ?? ''])->filter()->implode(' - '));
            $fields = [
                'name' => trim($data['name']),
                'document' => BrazilianDocuments::formatCpfCnpj($data['document']),
                'birth_date' => $data['birth_date'],
                'zip_code' => $data['zip_code'], 'street' => $data['street'], 'number' => $data['number'],
                'complement' => $data['complement'] ?? null, 'neighborhood' => $data['neighborhood'],
                'city' => $data['city'], 'state' => mb_strtoupper((string) $data['state']),
                'address' => collect([$line, $details])->filter()->implode(' | '),
                'pix_key_type' => ($data['pix_key_type'] ?? '') ?: null,
                'pix_key' => ($data['pix_key'] ?? '') ?: null,
            ];
            $role = $this->roleFor($user);
            if ($contact) {
                $phones = array_values(array_unique(array_filter(array_merge([$phone], (array) $contact->phones))));
                $emails = array_values(array_unique(array_filter(array_merge([$email], array_map('mb_strtolower', (array) $contact->emails)))));
                $roles = array_values(array_unique(array_merge($contact->roleList(), [$role])));
                $contact->update($fields + ['phone' => $phone, 'phones' => $phones, 'email' => $email, 'emails' => $emails, 'roles' => $roles]);
            } else {
                $contact = Contact::create($fields + ['type' => $role, 'roles' => [$role], 'phone' => $phone, 'phones' => [$phone], 'email' => $email, 'emails' => [$email], 'status' => true]);
            }
            $user->forceFill(['name' => $fields['name'], 'contact_id' => $contact->id, 'profile_completed_at' => now()])->save();
            // Liga participantes deste e-mail à conta e preenche o celular só onde está vazio (manual não é sobrescrito).
            DivisionMember::whereNull('user_id')->whereRaw('lower(email) = ?', [$email])->update(['user_id' => $user->id]);
            // Nome real substitui o apelido nos grupos e no convite.
            DivisionMember::whereRaw('lower(email) = ?', [$email])->where('is_owner', false)->update(['name' => $fields['name']]);
            \App\Models\UserInvitation::whereRaw('lower(email) = ?', [$email])->update(['name' => $fields['name']]);
            DivisionMember::whereRaw('lower(email) = ?', [$email])->where('is_owner', false)->whereNull('phone')
                ->update(['phone' => '+55'.BrazilianDocuments::digits($data['phone'])]);

            return $contact;
        });
    }

    /** Celular alterado em Divisões → atualiza o Contato ligado ao usuário desse e-mail. */
    public function syncContactPhone(?string $email, ?string $e164): void
    {
        if (! $email) {
            return;
        }
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->whereNotNull('contact_id')->first();
        $contact = $user ? Contact::find($user->contact_id) : null;
        if (! $contact || ! $e164) {
            return;
        }
        $phone = BrazilianDocuments::formatPhone(substr($e164, 3));
        $contact->update(['phone' => $phone, 'phones' => array_values(array_unique(array_filter(array_merge([$phone], (array) $contact->phones))))]);
    }
}
