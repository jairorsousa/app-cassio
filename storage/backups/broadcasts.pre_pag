<?php

use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads;

    public string $message = '';
    public $attachment = null;
    public array $contacts = [];
    public array $labels = [];
    public string $selectedLabel = '';
    public bool $loading = true;
    public bool $loadingLabels = true;
    public string $errorMessage = '';
    public string $labelsErrorMessage = '';

    public function mount()
    {
        $this->loadLabels();
        $this->loadContacts();
    }

    public function updatedSelectedLabel(): void
    {
        $this->loadContacts();
    }

    public function loadLabels(): void
    {
        $this->loadingLabels = true;
        $this->labelsErrorMessage = '';

        try {
            $response = $this->chatwootRequest()
                ->get($this->chatwootUrl('labels'));

            if ($response->failed()) {
                $this->labelsErrorMessage = 'Falha ao carregar labels do Chatwoot.';
                return;
            }

            $this->labels = $this->normalizeLabels($response->json());

            if ($this->selectedLabel === '' && ! empty($this->labels)) {
                $availableLabels = collect($this->labels)->pluck('title');
                $this->selectedLabel = $availableLabels->contains('parceiros')
                    ? 'parceiros'
                    : (string) $availableLabels->first();
            }
        } catch (\Exception $e) {
            $this->labelsErrorMessage = 'Erro ao carregar labels do Chatwoot: ' . $e->getMessage();
        } finally {
            $this->loadingLabels = false;
        }
    }

    public function loadContacts()
    {
        $this->contacts = [];
        $this->errorMessage = '';
        $this->loading = true;
        $page = 1;
        $pageContacts = [];

        if ($this->selectedLabel === '') {
            $this->errorMessage = 'Selecione uma label para carregar os contatos.';
            $this->loading = false;
            return;
        }

        try {
            do {
                $response = $this->chatwootRequest()
                    ->post($this->chatwootUrl('contacts/filter') . '?page=' . $page, [
                        'payload' => [
                            [
                                'attribute_key' => 'labels',
                                'filter_operator' => 'equal_to',
                                'values' => [$this->selectedLabel],
                                'query_operator' => null
                            ]
                        ]
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $pageContacts = $data['payload'] ?? [];
                    
                    if (!empty($pageContacts)) {
                        $this->contacts = array_merge($this->contacts, $pageContacts);
                    }
                    
                    $page++;
                } else {
                    $this->errorMessage = 'Falha ao carregar contatos do Chatwoot.';
                    break;
                }
            } while (!empty($pageContacts) && count($pageContacts) >= 15); // Continue looping while we receive full pages
        } catch (\Exception $e) {
            $this->errorMessage = 'Erro ao conectar com Chatwoot: ' . $e->getMessage();
        }
        $this->loading = false;
    }

    public function send(): void
    {
        $this->validate([
            'message' => 'required|string|max:1000',
            'attachment' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm|max:12288',
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

        $attachment = null;

        if ($this->attachment) {
            $attachment = [
                'path' => $this->attachment->store('broadcast-attachments', 'local'),
                'name' => $this->attachment->getClientOriginalName(),
                'mime_type' => $this->attachment->getMimeType(),
            ];
        }

        // Dispatch background job to send messages with sleep intervals
        \App\Jobs\SendBroadcastMessage::dispatch($contactIds, $this->message, $attachment);

        session()->flash('status', 'Envio iniciado! As mensagens estão sendo processadas em segundo plano (com o delay configurado).');
        $this->reset('message', 'attachment');
    }

    private function chatwootRequest()
    {
        return Http::withHeaders([
            'api_access_token' => (string) config('services.chatwoot.api_access_token'),
            'Content-Type' => 'application/json',
        ]);
    }

    private function chatwootUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('services.chatwoot.base_url'), '/');
        $accountId = config('services.chatwoot.account_id');

        return "{$baseUrl}/api/v1/accounts/{$accountId}/" . ltrim($path, '/');
    }

    private function normalizeLabels(?array $data): array
    {
        $labels = $data['payload'] ?? $data ?? [];

        return collect($labels)
            ->map(function ($label) {
                if (is_string($label)) {
                    return ['title' => $label, 'color' => null];
                }

                if (! is_array($label)) {
                    return null;
                }

                $title = $label['title'] ?? $label['name'] ?? null;

                if (! is_string($title) || trim($title) === '') {
                    return null;
                }

                return [
                    'title' => trim($title),
                    'color' => $label['color'] ?? null,
                ];
            })
            ->filter()
            ->unique('title')
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
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

            <form wire:submit="send" class="flex flex-col gap-sm" enctype="multipart/form-data">
                <div>
                    <label for="message" class="block text-sm font-medium text-mono-700 mb-xxxs">Mensagem</label>
                    <textarea
                        id="message"
                        name="message"
                        wire:model="message"
                        rows="7"
                        maxlength="1000"
                        class="w-full min-h-[180px] resize-y rounded-2xl border border-mono-200 bg-mono-white px-4 py-3 text-sm leading-6 text-mono-900 placeholder:text-mono-300 transition-all duration-200 hover:border-mono-300 focus:border-primary-500 focus:outline-none focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]"
                        placeholder="Digite sua mensagem aqui..."
                        required
                    ></textarea>
                    @error('message') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="attachment" class="block text-sm font-medium text-mono-700 mb-xxxs">Mídia</label>
                    <label
                        for="attachment"
                        class="flex cursor-pointer items-center gap-xs rounded-2xl border border-dashed border-mono-200 bg-mono-50 px-4 py-3 text-sm text-mono-600 transition-colors hover:border-primary-300 hover:bg-primary-50/40"
                    >
                        <span class="material-icons-outlined text-[20px] text-mono-500">attach_file</span>
                        <span class="min-w-0 flex-1 truncate">
                            @if($attachment)
                                {{ $attachment->getClientOriginalName() }}
                            @else
                                Imagem ou vídeo até 12 MB
                            @endif
                        </span>
                    </label>
                    <input
                        id="attachment"
                        type="file"
                        wire:model="attachment"
                        accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm"
                        class="sr-only"
                    >
                    <div wire:loading wire:target="attachment" class="mt-1 text-xs text-mono-500">Carregando mídia...</div>
                    @error('attachment') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="flex justify-end mt-sm">
                    <x-fx.button
                        type="submit"
                        variant="primary"
                        class="flex items-center gap-xs px-md py-sm"
                        wire:loading.attr="disabled"
                        wire:target="attachment,send"
                    >
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
                <h3 class="text-md font-semibold text-mono-900">Contatos</h3>
                <span class="text-xs bg-primary-100 text-primary-700 px-2 py-1 rounded-full font-medium">
                    {{ count($contacts) }}
                </span>
            </div>

            <div class="mb-sm">
                <label for="selectedLabel" class="block text-sm font-medium text-mono-700 mb-xxxs">Label do Chatwoot</label>
                <div class="flex gap-xxs">
                    <select
                        id="selectedLabel"
                        wire:model.live="selectedLabel"
                        class="min-w-0 flex-1 rounded-xl border border-mono-200 bg-mono-white px-3 py-2 text-sm text-mono-900 transition-all duration-200 hover:border-mono-300 focus:border-primary-500 focus:outline-none focus:ring-0 focus:shadow-[0_0_0_3px_rgba(255,111,0,.1)]"
                        @disabled($loadingLabels || empty($labels))
                    >
                        @if(empty($labels))
                            <option value="">Nenhuma label encontrada</option>
                        @else
                            @foreach($labels as $label)
                                <option value="{{ $label['title'] }}">{{ $label['title'] }}</option>
                            @endforeach
                        @endif
                    </select>

                    <button
                        type="button"
                        wire:click="loadContacts"
                        wire:loading.attr="disabled"
                        wire:target="loadContacts,selectedLabel"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-mono-200 text-mono-600 transition-colors hover:bg-mono-50 hover:text-mono-900 disabled:cursor-not-allowed disabled:opacity-60"
                        title="Recarregar contatos"
                        aria-label="Recarregar contatos"
                        @disabled($selectedLabel === '')
                    >
                        <span class="material-icons-outlined text-[20px]">refresh</span>
                    </button>
                </div>

                @if($labelsErrorMessage)
                    <div class="mt-2 text-xs text-red-500">{{ $labelsErrorMessage }}</div>
                @endif
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
