<?php

namespace App\Support;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Models\DivisionMember;
use App\Models\User;

/**
 * Mantém o nome da mesma pessoa igual em Contatos, no usuário e nos participantes do Divisões.
 * Vínculo: users.contact_id → contato; participante → user_id ou mesmo e-mail (do usuário ou do contato).
 * O caminho inverso (Divisões › "Editar nome", só admin) fica em DivisionService::setMemberName.
 */
class PersonNameSync
{
    public static function fromContact(Contact $contact): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $contact->name));
        if (mb_strlen($name) < 2) {
            return;
        }

        // O administrador (dono dos grupos) não é renomeado por aqui, igual ao "Editar nome".
        $users = User::where('contact_id', $contact->id)->get()->reject(fn (User $u) => $u->isAdmin());
        foreach ($users as $user) {
            if ($user->name !== $name) {
                $user->forceFill(['name' => $name])->save();
            }
        }

        $emails = $users->pluck('email')->push($contact->email)->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values()->all();
        $ids = $users->pluck('id')->all();
        if ($emails === [] && $ids === []) {
            return;
        }

        DivisionMember::query()
            ->where('is_owner', false)
            ->where(function ($q) use ($ids, $emails) {
                if ($ids !== []) {
                    $q->orWhereIn('user_id', $ids);
                }
                if ($emails !== []) {
                    $q->orWhereRaw('lower(email) in ('.implode(',', array_fill(0, count($emails), '?')).')', $emails);
                }
            })
            ->where('name', '!=', $name)
            ->update(['name' => $name]);
    }
}
