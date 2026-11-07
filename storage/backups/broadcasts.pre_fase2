<?php

use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads;

    public string $message = '';
    public string $link = '';
    public bool $splitParagraphs = true;
    public bool $reviewing = false;
    public ?string $reviewHash = null;
    public ?string $reviewNotice = null;
    public $attachment = null;
    public array $contacts = [];
    public array $labels = [];
    public string $selectedLabel = '';
    public string $contactSearch = '';
    public int $contactPage = 1;
    public const CONTACTS_PER_PAGE = 20;
    public bool $loading = true;
    public bool $loadingLabels = true;
    public string $errorMessage = '';
    public string $labelsErrorMessage = '';

    public function mount()
    {
        $this->loadLabels();
        $this->loadContacts();
    }

    /** Qualquer mudança no que será enviado invalida a revisão. */
    public function updated($property): void
    {
        if ($this->reviewing && in_array(explode('.', $property)[0], ['message', 'link', 'splitParagraphs', 'attachment', 'selectedLabel'], true)) {
            $this->reviewing = false;
            $this->reviewHash = null;
            $this->reviewNotice = 'Você alterou a mensagem ou o público: revise novamente antes de enviar.';
        }
    }

    private function fingerprint(): string
    {
        return sha1(json_encode([$this->message, $this->link, $this->splitParagraphs, $this->selectedLabel, $this->attachment ? [$this->attachment->getClientOriginalName(), $this->attachment->getSize()] : null, array_column($this->contacts, 'id')]));
    }

    private function validateDraft(): void
    {
        $this->validate([
            'message' => 'required|string|max:1000',
            'link' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                if (\App\Support\BroadcastComposer::normalizeUrl($value) === false) {
                    $fail('Link inválido. Use um endereço http:// ou https:// (ex.: instagram.com/seuperfil).');
                }
            }],
            'attachment' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm|max:12288',
        ]);
    }

    /** Etapa 1: revisar (nada é enviado). */
    public function review(): void
    {
        $this->reviewNotice = null;
        $this->validateDraft();
        if (empty($this->contacts)) {
            $this->addError('message', 'Nenhum contato na lista para enviar a mensagem.');

            return;
        }
        $this->reviewing = true;
        $this->reviewHash = $this->fingerprint();
    }

    public function cancelReview(): void
    {
        $this->reviewing = false;
        $this->reviewHash = null;
    }

    public function updatedContactSearch(): void
    {
        $this->contactPage = 1;
    }

    public function contactPageTo(int $page): void
    {
        $this->contactPage = max(1, $page);
    }

    /** Lista filtrada (antes de paginar). Envio continua usando todos os contatos da label. */
    private function filteredContacts(): array
    {
        $s = mb_strtolower(trim($this->contactSearch));
        if ($s === '') {
            return $this->contacts;
        }

        return array_values(array_filter($this->contacts, fn ($c) => str_contains(mb_strtolower(($c['name'] ?? '').' '.($c['phone_number'] ?? '')), $s)));
    }

    public function with(): array
    {
        $all = $this->filteredContacts();
        $pages = max(1, (int) ceil(count($all) / self::CONTACTS_PER_PAGE));
        $page = min($this->contactPage, $pages);

        return ['preview' => \App\Support\BroadcastComposer::compose($this->message, $this->link, $this->splitParagraphs), 'audience' => \App\Support\BroadcastComposer::audienceStats($this->contacts), 'pageContacts' => array_slice($all, ($page - 1) * self::CONTACTS_PER_PAGE, self::CONTACTS_PER_PAGE), 'contactMatches' => count($all), 'contactPages' => $pages, 'contactPageNow' => $page];
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
        $this->contactPage = 1;
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
                        // Guarda só o necessário (id/nome/telefone): o objeto completo do Chatwoot pesava o estado da página.
                        $slim = array_map(fn ($c) => ['id' => $c['id'] ?? null, 'name' => $c['name'] ?? null, 'phone_number' => $c['phone_number'] ?? null], $pageContacts);
                        $this->contacts = array_merge($this->contacts, $slim);
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

    /** Envio direto não existe mais: abre a revisão. */
    public function send(): void
    {
        $this->review();
    }

    /** Etapa 2: só depois da revisão e sem alterações desde então. */
    public function confirmSend(): void
    {
        if (! $this->reviewing || $this->reviewHash === null || ! hash_equals($this->reviewHash, $this->fingerprint())) {
            $this->reviewing = false;
            $this->reviewNotice = 'Revise a mensagem antes de confirmar o envio.';

            return;
        }
        if (! \Illuminate\Support\Facades\Cache::add('broadcast-confirm:'.auth()->id(), 1, 15)) {
            $this->reviewNotice = 'Envio já iniciado. Aguarde alguns segundos.';

            return;
        }
        $this->validateDraft();
        $this->reviewing = false;
        $this->reviewHash = null;

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
        \App\Jobs\SendBroadcastMessage::dispatch($contactIds, \App\Support\BroadcastComposer::compose($this->message, $this->link, $this->splitParagraphs), $attachment);

        session()->flash('status', 'Envio iniciado! As mensagens estão sendo processadas em segundo plano (com o delay configurado).');
        $this->reset('message', 'attachment', 'link');
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

            <form wire:submit="review" class="flex flex-col gap-sm" enctype="multipart/form-data">
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
                    <label class="mt-2 flex items-center gap-2 text-sm text-mono-700"><input type="checkbox" wire:model.live="splitParagraphs"> Separar parágrafos automaticamente</label>
                    <p class="text-xs text-mono-600">Ligado: cada Enter vira um parágrafo com linha em branco, como no WhatsApp. Desligado: o texto sai exatamente como digitado.</p>
                </div>

                <div>
                    <label for="link" class="block text-sm font-medium text-mono-700 mb-xxxs">Link (opcional)</label>
                    <input id="link" type="text" inputmode="url" wire:model.live.debounce.500ms="link" maxlength="500" placeholder="ex.: instagram.com/seuperfil" class="h-10 w-full rounded-xl border border-mono-200 bg-mono-white px-3 text-sm">
                    <p class="text-xs text-mono-600">Vai no fim da mensagem, depois de uma linha em branco. Sem http, usamos https://. O link não é aberto pelo sistema.</p>
                    @error('link') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                </div>

                <div>
                    <p class="block text-sm font-medium text-mono-700 mb-xxxs">Prévia (exatamente o texto que será enviado)</p>
                    <div class="whitespace-pre-wrap break-words rounded-2xl border border-mono-100 bg-mono-50 px-4 py-3 text-sm text-mono-900" data-broadcast-preview>{{ $preview !== '' ? $preview : '—' }}</div>
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
                        <span>Revisar envio</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                    </x-fx.button>
                </div>
            </form>
        </x-fx.card>

        @if ($reviewNotice)<x-fx.alert variant="warning" class="mt-md">{{ $reviewNotice }}</x-fx.alert>@endif

        @if ($reviewing)
            <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="review-title" data-broadcast-review>
                <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-mono-white p-5 shadow-elevated">
                    <h3 id="review-title" class="text-lg font-bold text-mono-900">Revisar envio</h3>
                    <p class="mt-1 text-sm text-mono-600">Etiqueta <strong>{{ $selectedLabel }}</strong>: a mensagem vai para todos os contatos carregados da etiqueta.</p>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <dt class="text-mono-600">Contatos na etiqueta</dt><dd class="text-right font-semibold" data-review-total>{{ $audience['total'] }}</dd>
                        <dt class="text-mono-600">Com telefone válido</dt><dd class="text-right">{{ $audience['valid'] }}</dd>
                        <dt class="text-mono-600">Sem telefone</dt><dd class="text-right">{{ $audience['missing_phone'] }}</dd>
                        <dt class="text-mono-600">Telefone inválido</dt><dd class="text-right">{{ $audience['invalid_phone'] }}</dd>
                        <dt class="text-mono-600">Telefones repetidos</dt><dd class="text-right">{{ $audience['duplicates'] }}</dd>
                    </dl>
                    @if ($audience['duplicates'] > 0)<p class="mt-1 text-xs text-mono-600">Repetidos ainda não são removidos nesta versão: essas pessoas podem receber mais de uma vez.</p>@endif
                    @if ($attachment)<p class="mt-2 text-sm">Mídia: {{ $attachment->getClientOriginalName() }}</p>@endif
                    <p class="mt-3 text-sm font-medium text-mono-700">Mensagem</p>
                    <div class="mt-1 whitespace-pre-wrap break-words rounded-xl border border-mono-100 bg-mono-50 px-3 py-2 text-sm">{{ $preview }}</div>
                    <p class="mt-3 rounded-xl bg-down-bg px-3 py-2 text-sm text-down">Depois de confirmar, o envio começa em segundo plano (45 a 90 s entre cada contato) e não pode ser desfeito por esta tela.</p>
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="cancelReview" class="h-10 rounded-xl border border-mono-200 px-4 text-sm">Voltar e editar</button>
                        <button type="button" wire:click="confirmSend" wire:loading.attr="disabled" wire:target="confirmSend" class="h-10 rounded-xl bg-primary-500 px-4 text-sm font-semibold text-white disabled:opacity-60" data-confirm-send>Confirmar e iniciar envio</button>
                    </div>
                </div>
            </div>
        @endif
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
                <p class="text-xs text-mono-600">{{ count($contacts) }} contato(s) na label; a mensagem vai para todos.</p>
                <input type="search" wire:model.live.debounce.300ms="contactSearch" placeholder="Buscar nome ou telefone" aria-label="Buscar contato" class="h-10 w-full rounded-xl border border-mono-200 bg-mono-white px-3 text-sm">
                <div class="flex flex-col gap-xxs" data-contact-list>
                    @if ($contactMatches === 0)<div class="text-sm text-mono-500 italic py-xs">Nenhum contato com esse filtro.</div>@endif
                    @foreach($pageContacts as $contact)
                        <div class="flex flex-col py-2 px-3 bg-mono-50 border border-mono-100 rounded-md">
                            <span class="text-sm font-medium text-mono-900 truncate">{{ $contact['name'] ?? 'Sem Nome' }}</span>
                            <span class="text-xs text-mono-600">{{ $contact['phone_number'] ?? 'Sem Número' }}</span>
                        </div>
                    @endforeach
                </div>
                @if ($contactPages > 1)
                    <div class="flex items-center justify-between gap-2 text-sm" data-contact-pages>
                        <button type="button" wire:click="contactPageTo({{ $contactPageNow - 1 }})" class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50" @disabled($contactPageNow <= 1)>Anterior</button>
                        <span class="text-xs text-mono-600">Página {{ $contactPageNow }} de {{ $contactPages }} · {{ $contactMatches }} contato(s)</span>
                        <button type="button" wire:click="contactPageTo({{ $contactPageNow + 1 }})" class="h-9 rounded-xl border border-mono-200 px-3 disabled:opacity-50" @disabled($contactPageNow >= $contactPages)>Próxima</button>
                    </div>
                @endif
            @endif
        </x-fx.card>
    </div>
</div>
