<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Contacts\Models\Contact;
use Illuminate\Support\Facades\DB;

class BrokerProfileService
{
    public function findForContact(Contact $contact): ?Broker
    {
        $query = Broker::query();

        $broker = $query->where('contact_id', $contact->id)->first();

        if (! $broker && $contact->document) {
            $broker = Broker::where('document', $contact->document)
                ->where(fn ($query) => $query->whereNull('contact_id')->orWhere('contact_id', $contact->id))
                ->first();
        }

        if (! $broker) {
            $broker = Broker::whereNull('contact_id')
                ->where('name', $contact->name)
                ->first();
        }

        return $broker;
    }

    public function forContact(Contact $contact): Broker
    {
        if ($contact->type !== 'corretor') {
            throw new \DomainException('Somente contatos do tipo corretor podem ter movimentação de corretor.');
        }

        return DB::transaction(function () use ($contact) {
            $broker = $this->findForContact($contact);
            $attributes = $this->attributesFromContact($contact);

            if ($broker) {
                $broker->fill($attributes);
                $broker->save();

                return $broker->fresh();
            }

            return Broker::create($attributes);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFromContact(Contact $contact): array
    {
        $phones = array_values(array_filter($contact->phones ?: [$contact->phone]));
        $emails = array_values(array_filter($contact->emails ?: [$contact->email]));

        return [
            'contact_id' => $contact->id,
            'name' => $contact->name,
            'document' => $contact->document,
            'birth_date' => $contact->birth_date,
            'phone' => $phones[0] ?? $contact->phone,
            'email' => $emails[0] ?? $contact->email,
            'address' => $this->addressFromContact($contact),
            'bank_name' => $contact->bank_name,
            'bank_agency' => $contact->bank_agency,
            'bank_account' => $contact->bank_account,
            'bank_account_type' => $contact->bank_account_type,
            'pix_key' => $contact->pix_key,
            'status' => $contact->status,
            'notes' => $contact->notes,
        ];
    }

    private function addressFromContact(Contact $contact): ?string
    {
        $parts = array_filter([
            $contact->street,
            $contact->number,
            $contact->complement,
            $contact->city,
            $contact->state,
            $contact->zip_code ? 'CEP '.$contact->zip_code : null,
        ]);

        return $parts ? implode(', ', $parts) : $contact->address;
    }
}
