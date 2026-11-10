<?php

namespace App\Support;

use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Brokers\Models\Broker;
use App\Domains\Brokers\Models\BrokerCommission;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Divisions\Models\DivisionMember;
use App\Models\User;
use App\Models\UserInvitation;

/**
 * Mantém o nome (e o CPF) da mesma pessoa igual em todos os módulos que guardam cópia.
 * Vínculo: users.contact_id → contato; participante do Divisões → user_id ou mesmo e-mail (do usuário ou do contato);
 * corretor → brokers.contact_id; repasse → receipt_destinations.contact_id ou comissão do corretor; convite → usuário/e-mail.
 * Telas que exibem pelo vínculo (empréstimos, recebíveis, lançamentos, cartões, requisitórios) não precisam de cópia.
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

        // Corretor ligado ao contato: nome e CPF iguais aos de Contatos.
        $brokers = Broker::withTrashed()->where('contact_id', $contact->id)->get();
        foreach ($brokers as $broker) {
            $changes = array_filter([
                'name' => $broker->name !== $name ? $name : null,
                'document' => (string) $broker->document !== (string) $contact->document ? ($contact->document ?: '') : null,
            ], fn ($v) => $v !== null);
            if (array_key_exists('document', $changes) && $changes['document'] === '') {
                $changes['document'] = null;
            }
            if ($changes !== []) {
                $broker->forceFill($changes)->save();
            }
        }

        // Repasses: cliente ligado ao contato e parte do corretor (pela comissão).
        ReceiptDestination::where('contact_id', $contact->id)->where('beneficiary', '!=', $name)->update(['beneficiary' => $name]);
        if ($brokers->isNotEmpty()) {
            ReceiptDestination::where('kind', 'broker')
                ->whereIn('commission_id', BrokerCommission::whereIn('broker_id', $brokers->pluck('id'))->select('id'))
                ->where('beneficiary', '!=', $name)->update(['beneficiary' => $name]);
        }

        $emails = $users->pluck('email')->merge(array_merge([$contact->email], (array) $contact->emails))->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values()->all();
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

        // Convite (Configurações › Usuários) mostra o mesmo nome.
        UserInvitation::query()
            ->where(function ($q) use ($ids, $emails) {
                if ($ids !== []) {
                    $q->orWhereIn('accepted_user_id', $ids);
                }
                if ($emails !== []) {
                    $q->orWhereRaw('lower(email) in ('.implode(',', array_fill(0, count($emails), '?')).')', $emails);
                }
            })
            ->where('name', '!=', $name)
            ->update(['name' => $name]);
    }
}
