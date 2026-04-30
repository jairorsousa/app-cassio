<?php

use App\Domains\Contacts\Models\Contact;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Contact $contact;

    public function mount(Contact $contact): void
    {
        $this->contact = $contact;
    }
}; ?>

<x-slot name="header">{{ $contact->name }}</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))
        <x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-md">
        {{-- Dados cadastrais --}}
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Dados Cadastrais</h3>
            <div class="grid grid-cols-2 gap-xs text-sm">
                <div><span class="text-mono-600">Documento:</span> {{ $contact->document ?: '—' }}</div>
                <div><span class="text-mono-600">RG:</span> {{ $contact->rg ?: '—' }}</div>
                <div><span class="text-mono-600">Nascimento:</span> {{ $contact->birth_date?->format('d/m/Y') ?: '—' }}</div>
                <div>
                    <span class="text-mono-600">Status:</span>
                    <x-fx.badge :variant="$contact->status ? 'up' : 'neutral'">
                        {{ $contact->status ? 'Ativo' : 'Inativo' }}
                    </x-fx.badge>
                </div>
                <div><span class="text-mono-600">Telefone:</span> {{ $contact->phone ?: '—' }}</div>
                <div><span class="text-mono-600">E-mail:</span> {{ $contact->email ?: '—' }}</div>
                <div class="col-span-2"><span class="text-mono-600">Endereço:</span> {{ $contact->address ?: '—' }}</div>
            </div>

            <h3 class="text-md font-semibold mt-md mb-sm">Dados Bancários</h3>
            <div class="grid grid-cols-2 gap-xs text-sm">
                <div><span class="text-mono-600">Banco:</span> {{ $contact->bank_name ?: '—' }}</div>
                <div><span class="text-mono-600">Agência:</span> {{ $contact->bank_agency ?: '—' }}</div>
                <div><span class="text-mono-600">Conta:</span> {{ $contact->bank_account ?: '—' }}</div>
                <div><span class="text-mono-600">Tipo:</span> {{ $contact->bank_account_type ?: '—' }}</div>
                <div class="col-span-2"><span class="text-mono-600">PIX:</span> {{ $contact->pix_key ?: '—' }}</div>
            </div>

            @if ($contact->notes)
                <h3 class="text-md font-semibold mt-md mb-sm">Observações</h3>
                <p class="text-sm text-mono-700">{{ $contact->notes }}</p>
            @endif

            <div class="mt-md flex gap-xs">
                <a href="{{ route('contacts.edit', $contact) }}" class="fx-btn fx-btn--standard fx-btn--sm">Editar</a>
                <a href="{{ route('contacts.index') }}" class="fx-btn fx-btn--text fx-btn--sm">← Voltar</a>
            </div>
        </x-fx.card>

        {{-- Info rápida --}}
        <div class="flex flex-col gap-md">
            <x-fx.card>
                <h3 class="text-md font-semibold mb-sm">Informações</h3>
                <div class="flex flex-col gap-xs text-sm text-mono-700">
                    <div class="flex justify-between">
                        <span class="text-mono-600">Cadastrado em</span>
                        <span>{{ $contact->created_at->format('d/m/Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-mono-600">Última atualização</span>
                        <span>{{ $contact->updated_at->format('d/m/Y') }}</span>
                    </div>
                </div>
            </x-fx.card>

            @if ($contact->phone || $contact->email || $contact->pix_key)
                <x-fx.card>
                    <h3 class="text-md font-semibold mb-sm">Contato Rápido</h3>
                    <div class="flex flex-col gap-xs">
                        @if ($contact->phone)
                            <a href="tel:{{ $contact->phone }}" class="fx-btn fx-btn--standard fx-btn--sm w-full text-center">
                                📞 {{ $contact->phone }}
                            </a>
                        @endif
                        @if ($contact->email)
                            <a href="mailto:{{ $contact->email }}" class="fx-btn fx-btn--standard fx-btn--sm w-full text-center">
                                ✉️ {{ $contact->email }}
                            </a>
                        @endif
                        @if ($contact->pix_key)
                            <div class="fx-btn fx-btn--mono fx-btn--sm w-full text-center cursor-default">
                                🏦 PIX: {{ $contact->pix_key }}
                            </div>
                        @endif
                    </div>
                </x-fx.card>
            @endif
        </div>
    </div>
</div>
