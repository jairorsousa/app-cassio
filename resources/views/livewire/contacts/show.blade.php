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

    public function delete()
    {
        $this->contact->delete();

        session()->flash('status', 'Contato removido.');
        return $this->redirectRoute('contacts.index', navigate: true);
    }
}; ?>

<x-slot name="header">{{ $contact->name }}</x-slot>

<div class="flex flex-col gap-6">
    @if (session('status'))
        <x-jr.alert variant="success">{{ session('status') }}</x-jr.alert>
    @endif

    @php
        $phoneList = array_values(array_filter($contact->phones ?: [$contact->phone]));
        $emailList = array_values(array_filter($contact->emails ?: [$contact->email]));
        $statusVariant = $contact->status ? 'success' : 'neutral';
        $statusLabel = $contact->status ? 'Ativo' : 'Inativo';
        $cityState = $contact->city ? trim($contact->city.' / '.$contact->state) : '—';
    @endphp

    <a href="{{ route('contacts.index') }}" class="inline-flex w-fit items-center gap-2 text-sm font-medium text-mono-600 transition-colors hover:text-primary-500">
        <span class="material-icons-outlined text-[18px]">arrow_back</span>
        Voltar
    </a>

    <x-jr.card>
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-center gap-4">
                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-[44px]">person</span>
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="truncate text-3xl font-bold text-mono-900">{{ $contact->name }}</h2>
                        <x-jr.badge :variant="$statusVariant">
                            <span class="mr-1 h-1.5 w-1.5 rounded-full {{ $contact->status ? 'bg-success' : 'bg-mono-400' }}"></span>
                            {{ $statusLabel }}
                        </x-jr.badge>
                    </div>
                    <p class="mt-1 text-sm text-mono-600">Contato</p>
                </div>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('contacts.edit', $contact) }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-mono-200 px-5 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-50">
                    <span class="material-icons-outlined text-[18px]">edit</span>
                    Editar
                </a>
                <button
                    type="button"
                    wire:click="delete"
                    wire:confirm="Excluir este contato? Essa ação não pode ser desfeita."
                    class="inline-flex h-11 items-center gap-2 rounded-xl border border-down px-5 text-sm font-semibold text-down transition-colors hover:bg-down-bg"
                >
                    <span class="material-icons-outlined text-[18px]">delete</span>
                    Excluir
                </button>
                <a href="{{ route('contacts.index') }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-primary-500 px-5 text-sm font-semibold text-primary-500 transition-colors hover:bg-primary-100">
                    <span class="material-icons-outlined text-[18px]">arrow_back</span>
                    Voltar
                </a>
            </div>
        </div>
    </x-jr.card>

    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-jr.card>
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-[30px]">badge</span>
                </div>
                <div>
                    <div class="text-sm text-mono-600">Tipo</div>
                    <div class="text-xl font-bold text-mono-900">{{ $contact->typeLabel() }}</div>
                </div>
            </div>
        </x-jr.card>
        <x-jr.card>
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-[30px]">monitor_heart</span>
                </div>
                <div>
                    <div class="text-sm text-mono-600">Status</div>
                    <x-jr.badge :variant="$statusVariant">{{ $statusLabel }}</x-jr.badge>
                </div>
            </div>
        </x-jr.card>
        <x-jr.card>
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-[30px]">location_on</span>
                </div>
                <div>
                    <div class="text-sm text-mono-600">Cidade/UF</div>
                    <div class="text-xl font-bold text-mono-900">{{ $cityState }}</div>
                </div>
            </div>
        </x-jr.card>
        <x-jr.card>
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-500">
                    <span class="material-icons-outlined text-[30px]">event</span>
                </div>
                <div>
                    <div class="text-sm text-mono-600">Última atualização</div>
                    <div class="text-xl font-bold text-mono-900">{{ $contact->updated_at->format('d/m/Y') }}</div>
                </div>
            </div>
        </x-jr.card>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1.05fr_.95fr]">
        <x-jr.card>
            <div class="mb-5 flex items-center gap-3">
                <span class="material-icons-outlined text-[24px] text-primary-500">contact_page</span>
                <h3 class="text-lg font-bold text-mono-900">Dados Cadastrais</h3>
            </div>

            <div class="grid grid-cols-1 gap-4 border-b border-dashed border-mono-200 pb-5 text-sm md:grid-cols-2">
                <div><span class="text-mono-600">Tipo:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->typeLabel() }}</span></div>
                <div><span class="text-mono-600">Documento:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->document ?: '—' }}</span></div>
                <div><span class="text-mono-600">Nascimento:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->birth_date?->format('d/m/Y') ?: '—' }}</span></div>
                <div>
                    <span class="text-mono-600">Status:</span>
                    <x-jr.badge class="ml-2" :variant="$statusVariant" size="sm">{{ $statusLabel }}</x-jr.badge>
                </div>
                <div>
                    <span class="text-mono-600">Telefones:</span>
                    <span class="ml-2 font-medium text-mono-900">{{ $phoneList ? implode(' / ', $phoneList) : '—' }}</span>
                </div>
                <div>
                    <span class="text-mono-600">E-mails:</span>
                    <span class="ml-2 font-medium text-mono-900">{{ $emailList ? implode(' / ', $emailList) : '—' }}</span>
                </div>
            </div>

            <div class="py-5">
                <div class="mb-4 flex items-center gap-3">
                    <span class="material-icons-outlined text-[24px] text-primary-500">home</span>
                    <h3 class="text-lg font-bold text-mono-900">Endereço</h3>
                </div>
                <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
                    <div><span class="text-mono-600">CEP:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->zip_code ?: '—' }}</span></div>
                    <div><span class="text-mono-600">Cidade/UF:</span> <span class="ml-2 font-medium text-mono-900">{{ $cityState }}</span></div>
                    <div><span class="text-mono-600">Endereço:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->street ?: ($contact->address ?: '—') }}</span></div>
                    <div><span class="text-mono-600">Complemento:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->complement ?: '—' }}</span></div>
                    <div><span class="text-mono-600">Número:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->number ?: '—' }}</span></div>
                </div>
            </div>

            <div class="border-t border-dashed border-mono-200 py-5">
                <div class="mb-4 flex items-center gap-3">
                    <span class="material-icons-outlined text-[24px] text-primary-500">account_balance</span>
                    <h3 class="text-lg font-bold text-mono-900">Dados Bancários</h3>
                </div>
                <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
                    <div><span class="text-mono-600">Banco:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->bank_name ?: '—' }}</span></div>
                    <div><span class="text-mono-600">Agência:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->bank_agency ?: '—' }}</span></div>
                    <div><span class="text-mono-600">Conta:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->bank_account ?: '—' }}</span></div>
                    <div><span class="text-mono-600">Tipo:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->bank_account_type ?: '—' }}</span></div>
                    <div><span class="text-mono-600">Tipo PIX:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->pixKeyTypeLabel() }}</span></div>
                    <div><span class="text-mono-600">PIX:</span> <span class="ml-2 font-medium text-mono-900">{{ $contact->pix_key ?: '—' }}</span></div>
                </div>
            </div>

            @if ($contact->notes)
                <div class="border-t border-dashed border-mono-200 pt-5">
                    <div class="mb-3 flex items-center gap-3">
                        <span class="material-icons-outlined text-[24px] text-primary-500">sticky_note_2</span>
                        <h3 class="text-lg font-bold text-mono-900">Observações</h3>
                    </div>
                    <p class="text-sm leading-6 text-mono-900">{{ $contact->notes }}</p>
                </div>
            @endif
        </x-jr.card>

        <div class="flex flex-col gap-6">
            <x-jr.card>
                <div class="mb-5 flex items-center gap-3">
                    <span class="material-icons-outlined text-[24px] text-primary-500">info</span>
                    <h3 class="text-lg font-bold text-mono-900">Informações</h3>
                </div>
                <div class="space-y-4 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-mono-600">Cadastrado em</span>
                        <span class="font-medium text-mono-900">{{ $contact->created_at->format('d/m/Y') }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-mono-600">Última atualização</span>
                        <span class="font-medium text-mono-900">{{ $contact->updated_at->format('d/m/Y') }}</span>
                    </div>
                </div>
            </x-jr.card>

            @if ($phoneList || $emailList || $contact->pix_key)
                <x-jr.card>
                    <div class="mb-5 flex items-center gap-3">
                        <span class="material-icons-outlined text-[24px] text-primary-500">phone</span>
                        <h3 class="text-lg font-bold text-mono-900">Contato Rápido</h3>
                    </div>
                    <div class="flex flex-col gap-4">
                        @foreach ($phoneList as $phone)
                            <a href="tel:{{ $phone }}" class="flex h-14 items-center justify-center gap-3 rounded-xl border border-mono-200 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-50">
                                <span class="material-icons-outlined text-[22px]">phone</span>
                                {{ $phone }}
                            </a>
                        @endforeach
                        @foreach ($emailList as $email)
                            <a href="mailto:{{ $email }}" class="flex h-14 items-center justify-center gap-3 rounded-xl border border-mono-200 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-50">
                                <span class="material-icons-outlined text-[22px]">mail</span>
                                {{ $email }}
                            </a>
                        @endforeach
                        @if ($contact->pix_key)
                            <div class="flex min-h-14 items-center justify-center gap-3 rounded-xl border border-mono-200 px-4 text-center text-sm font-semibold text-mono-900">
                                <span class="material-icons-outlined text-[22px]">key</span>
                                PIX {{ $contact->pixKeyTypeLabel() }}: {{ $contact->pix_key }}
                            </div>
                        @endif
                    </div>
                </x-jr.card>
            @endif
        </div>
    </div>
</div>
