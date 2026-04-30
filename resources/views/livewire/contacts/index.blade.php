<?php

use App\Domains\Contacts\Models\Contact;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';
    #[Url]
    public string $status = '';

    public function delete(int $id): void
    {
        Contact::findOrFail($id)->delete();
        session()->flash('status', 'Contato removido.');
    }

    public function with(): array
    {
        $q = Contact::query();
        if ($this->search) {
            $q->where(function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                      ->orWhere('document', 'like', '%'.$this->search.'%');
            });
        }
        if ($this->status !== '') {
            $q->where('status', $this->status === '1');
        }

        return [
            'contacts' => $q->orderBy('name')->paginate(25),
        ];
    }
}; ?>

<x-slot name="header">Contatos</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    <x-fx.card>
        <div class="flex flex-wrap items-end gap-xs">
            <x-fx.input label="Buscar" wire:model.live.debounce.500ms="search" placeholder="Nome ou CPF/CNPJ..." />
            <div>
                <label class="block text-xxs text-mono-600 mb-xxxs">Status</label>
                <select wire:model.live="status" class="fx-form-field">
                    <option value="">Todos</option>
                    <option value="1">Ativos</option>
                    <option value="0">Inativos</option>
                </select>
            </div>
            <x-fx.button href="{{ route('contacts.create') }}" variant="primary" size="sm">+ Novo contato</x-fx.button>
        </div>
    </x-fx.card>

    <x-fx.card>
        @if ($contacts->isEmpty())
            <div class="text-sm text-mono-600">Nenhum contato encontrado.</div>
        @else
            <table class="fx-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">Nome</th>
                        <th class="text-left">Documento</th>
                        <th class="text-left">Telefone</th>
                        <th class="text-left">E-mail</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contacts as $contact)
                        <tr>
                            <td>
                                <a href="{{ route('contacts.show', $contact) }}" class="font-medium hover:text-primary-500">{{ $contact->name }}</a>
                            </td>
                            <td>{{ $contact->document ?: '—' }}</td>
                            <td>{{ $contact->phone ?: '—' }}</td>
                            <td>{{ $contact->email ?: '—' }}</td>
                            <td class="text-center">
                                <x-fx.badge :variant="$contact->status ? 'up' : 'neutral'">{{ $contact->status ? 'Ativo' : 'Inativo' }}</x-fx.badge>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-xxs">
                                    <a href="{{ route('contacts.edit', $contact) }}" class="fx-btn fx-btn--text fx-btn--sm">Editar</a>
                                    <a href="{{ route('contacts.show', $contact) }}" class="fx-btn fx-btn--text fx-btn--sm">Ver</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-md">{{ $contacts->links() }}</div>
        @endif
    </x-fx.card>
</div>
