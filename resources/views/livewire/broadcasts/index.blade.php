<?php

use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $message = '';
    public array $contacts = [];
    public bool $loading = true;
    public string $errorMessage = '';

    public function mount()
    {
        $this->loadContacts();
    }

    public function loadContacts()
    {
        try {
            $response = Http::withHeaders([
                'api_access_token' => 'txw3GA1xq1PvVaB7buNca6Bk',
                'Content-Type' => 'application/json',
            ])->post('https://msa.vozconecta.com.br/api/v1/accounts/1/contacts/filter', [
                'payload' => [
                    [
                        'attribute_key' => 'labels',
                        'filter_operator' => 'equal_to',
                        'values' => ['parceiros'],
                        'query_operator' => null
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->contacts = $data['payload'] ?? [];
            } else {
                $this->errorMessage = 'Falha ao carregar contatos do Chatwoot.';
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Erro ao conectar com Chatwoot: ' . $e->getMessage();
        }
        $this->loading = false;
    }

    public function send(): void
    {
        $this->validate([
            'message' => 'required|string|max:1000',
        ]);

        if (empty($this->contacts)) {
            $this->addError('message', 'Nenhum contato na lista para enviar a mensagem.');
            return;
        }

        // Extract contact IDs from the contacts array
        $contactIds = collect($this->contacts)->pluck('id')->filter()->toArray();

        if (empty($contactIds)) {
            $this->addError('message', 'Não foi possível extrair os IDs dos contatos. Verifique a integração.');
            return;
        }

        // Dispatch background job to send messages with sleep intervals
        \App\Jobs\SendBroadcastMessage::dispatch($contactIds, $this->message);

        session()->flash('status', 'Envio iniciado! As mensagens estão sendo processadas em segundo plano (com o delay configurado).');
        $this->reset('message');
    }
}; ?>

<x-slot name="header">Lista de Transmissão</x-slot>

<div class="flex flex-col gap-md max-w-6xl mx-auto md:flex-row md:items-start">
    <div class="flex-1 w-full order-2 md:order-1">
        @if (session('status'))
            <x-fx.alert variant="success" class="mb-md">{{ session('status') }}</x-fx.alert>
        @endif

        <x-fx.card>
            <div class="mb-sm">
                <h2 class="text-lg font-semibold text-mono-900">Nova Mensagem de Transmissão</h2>
                <p class="text-sm text-mono-600">Escreva uma mensagem para enviar para os seus contatos, semelhante a uma lista de transmissão do WhatsApp.</p>
            </div>

            <form wire:submit="send" class="flex flex-col gap-sm">
                <div>
                    <label for="message" class="block text-sm font-medium text-mono-700 mb-xxxs">Mensagem</label>
                    <textarea
                        id="message"
                        wire:model="message"
                        rows="8"
                        class="fx-form-field w-full p-sm resize-none"
                        placeholder="Digite sua mensagem aqui..."
                        required
                    ></textarea>
                    @error('message') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="flex justify-end mt-sm">
                    <x-fx.button type="submit" variant="primary" class="flex items-center gap-xs px-md py-sm">
                        <span>Enviar Mensagem</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                    </x-fx.button>
                </div>
            </form>
        </x-fx.card>
    </div>

    {{-- Contact List Sidebar --}}
    <div class="w-full md:w-80 shrink-0 order-1 md:order-2">
        <x-fx.card>
            <div class="flex justify-between items-center mb-sm">
                <h3 class="text-md font-semibold text-mono-900">Contatos (Parceiros)</h3>
                <span class="text-xs bg-primary-100 text-primary-700 px-2 py-1 rounded-full font-medium">
                    {{ count($contacts) }}
                </span>
            </div>

            @if($errorMessage)
                <div class="text-sm text-red-500 bg-red-50 p-2 rounded">{{ $errorMessage }}</div>
            @elseif($loading)
                <div class="flex justify-center py-sm">
                    <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-primary-600"></div>
                </div>
            @elseif(empty($contacts))
                <div class="text-sm text-mono-500 italic py-xs">Nenhum contato encontrado.</div>
            @else
                <div class="flex flex-col gap-xxs max-h-[500px] overflow-y-auto pr-1">
                    @foreach($contacts as $contact)
                        <div class="flex flex-col py-2 px-3 bg-mono-50 border border-mono-100 rounded-md">
                            <span class="text-sm font-medium text-mono-900 truncate">{{ $contact['name'] ?? 'Sem Nome' }}</span>
                            <span class="text-xs text-mono-600">{{ $contact['phone_number'] ?? 'Sem Número' }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-fx.card>
    </div>
</div>
