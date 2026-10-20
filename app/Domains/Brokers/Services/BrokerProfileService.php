<?php

namespace App\Domains\Brokers\Services;

use App\Domains\Brokers\Models\Broker;
use App\Domains\Contacts\Models\Contact;
use Illuminate\Support\Facades\DB;

class BrokerProfileService
{
    /** Bring financial-only records into the contact directory without changing ledger IDs. */
    public function linkLegacyProfiles(): void
    {
        Broker::whereNull('contact_id')->orderBy('id')->eachById(function (Broker $broker) {
            $this->contactForBroker($broker);
        });
    }

    /** Prepare the same active directory for both the listing and receipt selection. */
    public function synchronizeDirectory(): void
    {
        $this->linkLegacyProfiles();
        Contact::where('type', 'corretor')->active()->orderBy('id')->eachById(function (Contact $contact) {
            $this->forContact($contact);
        });
    }

    public function contactForBroker(Broker $broker): ?Contact
    {
        return DB::transaction(function () use ($broker) {
            $broker = Broker::lockForUpdate()->findOrFail($broker->id);
            if ($broker->contact_id) {
                return $broker->contact()->where('type', 'corretor')->first();
            }

            // Include deleted contacts while matching, so a removed person is not recreated.
            $candidates = Contact::withTrashed()->where('type', 'corretor')
                ->whereDoesntHave('brokerProfile', fn ($q) => $q->withTrashed())->get();
            $document = $this->normalizedDocument($broker->document);
            $matches = $document !== ''
                ? $candidates->filter(fn ($contact) => $this->normalizedDocument($contact->document) === $document)
                : collect();
            if ($matches->isEmpty()) {
                $matches = $candidates->filter(fn ($contact) => mb_strtolower(trim($contact->name)) === mb_strtolower(trim($broker->name))
                    && ($document === '' || $this->normalizedDocument($contact->document) === '' || $this->normalizedDocument($contact->document) === $document)
                );
            }
            $contact = $matches->count() === 1 ? $matches->first() : null;
            if ($contact?->trashed()) {
                return null;
            }

            $attributes = $broker->only([
                'name', 'document', 'birth_date', 'phone', 'email', 'address',
                'bank_name', 'bank_agency', 'bank_account', 'bank_account_type', 'pix_key', 'status', 'notes',
            ]);
            $attributes['phones'] = array_values(array_filter([$broker->phone]));
            $attributes['emails'] = array_values(array_filter([$broker->email]));
            if (! $contact) {
                $contact = Contact::create($attributes + ['type' => 'corretor']);
            } else {
                $contact = Contact::lockForUpdate()->findOrFail($contact->id);
                // Preserve contact data, filling only information missing from the directory.
                foreach ($attributes as $field => $value) {
                    if (in_array($field, ['name', 'status'], true)) {
                        continue;
                    }
                    if ($contact->$field === null || $contact->$field === '' || $contact->$field === []) {
                        $contact->$field = $value;
                    }
                }
                $contact->save();
            }
            $broker->update(['contact_id' => $contact->id]);

            return $contact;
        });
    }

    private function normalizedDocument(?string $document): string
    {
        return preg_replace('/[^0-9]/', '', (string) $document) ?? '';
    }

    public function findForContact(Contact $contact): ?Broker
    {
        $linked = Broker::where('contact_id', $contact->id)->first();
        if ($linked) {
            return $linked;
        }

        $candidates = Broker::whereNull('contact_id')->get();
        $document = $this->normalizedDocument($contact->document);
        if ($document !== '') {
            $matches = $candidates->filter(fn ($broker) => $this->normalizedDocument($broker->document) === $document);
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }
        $matches = $candidates->filter(fn ($broker) => mb_strtolower(trim($broker->name)) === mb_strtolower(trim($contact->name))
            && ($document === '' || $this->normalizedDocument($broker->document) === '' || $this->normalizedDocument($broker->document) === $document)
        );

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function forContact(Contact $contact): Broker
    {
        if ($contact->type !== 'corretor') {
            throw new \DomainException('Somente contatos do tipo corretor podem ter movimentação de corretor.');
        }

        return DB::transaction(function () use ($contact) {
            $contact = Contact::where('type', 'corretor')->lockForUpdate()->findOrFail($contact->id);
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
