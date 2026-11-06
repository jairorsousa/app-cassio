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
    /** Etiquetas marcadas (nenhuma por padrão). */
    public array $selectedLabels = [];
    /** Contatos já carregados por etiqueta (id/nome/telefone), para não buscar de novo a cada clique. */
    public array $labelContacts = [];
    public ?int $lastBatchId = null;
    public string $tab = 'nova';
    public bool $includeOptOut = true;
    public string $sendMode = 'now';
    public string $scheduledDate = '';
    public string $scheduledTime = '';
    public string $weeklyPeriod = 'morning';

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['historico', 'nao-receber'], true) ? $tab : 'nova';
    }
    public string $contactSearch = '';
    public int $contactPage = 1;
    public const CONTACTS_PER_PAGE = 20;
    public bool $loading = false;
    public bool $loadingLabels = true;
    public string $errorMessage = '';
    public string $labelsErrorMessage = '';

    public function mount()
    {
        $this->loadLabels();
        $this->lastBatchId = \App\Domains\Broadcasts\Models\TransmissionBatch::where('created_by', auth()->id())->latest('id')->value('id');
    }

    private function audience(): array
    {
        return \App\Support\TransmissionAudience::withoutSuppressed(\App\Support\TransmissionAudience::build(array_intersect_key($this->labelContacts, array_flip($this->selectedLabels))));
    }

    private function refreshRecipients(): void
    {
        $this->contacts = array_map(fn ($r) => ['id' => $r['contact_id'], 'name' => $r['name'], 'phone_number' => $r['phone']], $this->audience()['recipients']);
        $this->contactPage = 1;
        if ($this->reviewing) {
            $this->reviewing = false;
            $this->reviewHash = null;
            $this->reviewNotice = 'Você alterou a mensagem ou o público: revise novamente antes de enviar.';
        }
    }

    public function toggleLabel(string $title): void
    {
        if (! in_array($title, array_column($this->labels, 'title'), true)) {
            return;
        }
        if (in_array($title, $this->selectedLabels, true)) {
            $this->selectedLabels = array_values(array_diff($this->selectedLabels, [$title]));
        } else {
            if (! array_key_exists($title, $this->labelContacts)) {
                $loaded = $this->fetchLabelContacts($title);
                if ($loaded === null) {
                    return;
                }
                $this->labelContacts[$title] = $loaded;
            }
            $this->selectedLabels[] = $title;
        }
        $this->refreshRecipients();
    }

    public function reloadSelected(): void
    {
        foreach ($this->selectedLabels as $t) {
            $loaded = $this->fetchLabelContacts($t);
            if ($loaded !== null) {
                $this->labelContacts[$t] = $loaded;
            }
        }
        $this->refreshRecipients();
    }

    /** Qualquer mudança no que será enviado invalida a revisão. */
    public function updated($property): void
    {
        if ($this->reviewing && in_array(explode('.', $property)[0], ['message', 'link', 'splitParagraphs', 'attachment', 'includeOptOut', 'sendMode', 'scheduledDate', 'scheduledTime', 'weeklyPeriod'], true)) {
            $this->reviewing = false;
            $this->reviewHash = null;
            $this->reviewNotice = 'Você alterou a mensagem ou o público: revise novamente antes de enviar.';
        }
    }

    private function fingerprint(): string
    {
        return sha1(json_encode([$this->message, $this->link, $this->splitParagraphs, $this->includeOptOut, $this->sendMode, $this->scheduledDate, $this->scheduledTime, $this->weeklyPeriod, $this->selectedLabels, $this->attachment ? [$this->attachment->getClientOriginalName(), $this->attachment->getSize()] : null, array_column($this->audience()['recipients'], 'phone_hash')]));
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
            'attachment' => ['nullable', 'file', 'max:12288',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/x-aac,audio/x-hx-aac-adts,audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/ogg,audio/opus,application/ogg',
                function ($attr, $file, $fail) {
                    $ext = strtolower((string) $file->getClientOriginalExtension());
                    if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'webm', 'mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus'], true)) {
                        $fail('Formato não aceito. Use imagem (JPG, PNG, WebP, GIF), vídeo (MP4, MOV, WebM) ou áudio (MP3, M4A, AAC, WAV, OGG, OPUS).');
                        return;
                    }
                    // Conteúdo real (não o nome/MIME informado pelo navegador).
                    $path = $file->getRealPath();
                    $head = $path ? (string) @file_get_contents($path, false, null, 0, 4) : '';
                    $real = $path ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) : '';
                    if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7FELF") || ! preg_match('#^(image|video|audio)/|^application/ogg$#', $real)) {
                        $fail('Arquivo não reconhecido como imagem, vídeo ou áudio válido.');
                    }
                }],
        ], ['attachment.mimetypes' => 'Arquivo não reconhecido como imagem, vídeo ou áudio válido.', 'attachment.max' => 'Arquivo maior que 12 MB.']);
    }

    /** Etapa 1: revisar (nada é enviado). */
    public function review(): void
    {
        $this->reviewNotice = null;
        $this->validateDraft();
        if (empty($this->selectedLabels)) {
            $this->addError('message', 'Escolha pelo menos uma etiqueta de destinatários.');

            return;
        }
        if ($this->audience()['unique'] === 0) {
            $this->addError('message', 'Nenhum contato com telefone válido nas etiquetas escolhidas.');

            return;
        }
        if (! $this->validSchedule()) {
            return;
        }
        $this->reviewing = true;
        $this->reviewHash = $this->fingerprint();
    }

    private function validSchedule(): bool
    {
        if (! in_array($this->sendMode, ['now', 'once', 'weekly'], true)) {
            $this->sendMode = 'now';
        }
        [, $error] = \App\Support\TransmissionScheduler::firstRun($this->sendMode, $this->scheduledDate, $this->scheduledTime, $this->weeklyPeriod);
        if ($error) {
            $this->addError('scheduledDate', $error);

            return false;
        }

        return true;
    }

    public function updatedSendMode(): void
    {
        if ($this->sendMode === 'weekly' && $this->scheduledDate === '') {
            $this->scheduledDate = now('America/Fortaleza')->next(\Carbon\Carbon::MONDAY)->format('Y-m-d');
        }
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

        $lastBatch = $this->lastBatchId ? \App\Domains\Broadcasts\Models\TransmissionBatch::where('created_by', auth()->id())->find($this->lastBatchId) : null;

        return ['preview' => \App\Support\BroadcastComposer::compose($this->message, $this->link, $this->splitParagraphs, $this->includeOptOut), 'firstRunLabel' => $this->sendMode !== 'now' ? (\App\Support\TransmissionScheduler::firstRun($this->sendMode, $this->scheduledDate, $this->scheduledTime, $this->weeklyPeriod)[0]?->setTimezone('America/Fortaleza')->format('d/m/Y H:i')) : null, 'audience' => $this->audience(), 'labelCounts' => array_map('count', $this->labelContacts), 'lastBatch' => $lastBatch, 'lastCounts' => $lastBatch?->counts(), 'pageContacts' => array_slice($all, ($page - 1) * self::CONTACTS_PER_PAGE, self::CONTACTS_PER_PAGE), 'contactMatches' => count($all), 'contactPages' => $pages, 'contactPageNow' => $page];
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

        } catch (\Exception $e) {
            $this->labelsErrorMessage = 'Erro ao carregar labels do Chatwoot: ' . $e->getMessage();
        } finally {
            $this->loadingLabels = false;
        }
    }

    /** Lê todos os contatos de UMA etiqueta (15 por página no Chatwoot). Só leitura. */
    private function fetchLabelContacts(string $label): ?array
    {
        $svc = app(\App\Support\ChatwootContacts::class);
        $out = $svc->forLabel($label);
        $this->errorMessage = $svc->error;

        return $out;
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

        $audience = $this->audience();
        if (empty($this->selectedLabels) || $audience['unique'] === 0) {
            $this->addError('message', 'Escolha pelo menos uma etiqueta com contatos válidos.');

            return;
        }

        $body = \App\Support\BroadcastComposer::compose($this->message, $this->link, $this->splitParagraphs, $this->includeOptOut);
        if ($this->sendMode !== 'now') {
            [$first, $error] = \App\Support\TransmissionScheduler::firstRun($this->sendMode, $this->scheduledDate, $this->scheduledTime, $this->weeklyPeriod);
            if ($error) {
                $this->addError('scheduledDate', $error);

                return;
            }
            $s = \App\Support\TransmissionScheduler::create(auth()->id(), $this->sendMode, $body, $this->selectedLabels, $this->attachment, $first, $this->weeklyPeriod);
            session()->flash('status', 'Transmissão agendada para '.$first->setTimezone('America/Fortaleza')->format('d/m/Y H:i').' (Fortaleza)'.($s->kind === 'weekly' ? ', depois uma vez por semana alternando segunda/terça/quarta e manhã/tarde' : '').'. Os contatos das etiquetas serão lidos de novo na hora do envio. Veja e cancele em Histórico.');
            $this->reset('message', 'attachment', 'link', 'sendMode', 'scheduledDate', 'scheduledTime', 'weeklyPeriod');

            return;
        }

        $batch = \App\Support\TransmissionDispatcher::start(auth()->id(), $body, $this->attachment, $audience, $this->selectedLabels);
        $this->lastBatchId = $batch->id;

        session()->flash('status', 'Envio iniciado para '.$batch->recipients_count.' pessoa(s), uma por vez, com 45 a 90 s entre cada.');
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

<div class="max-w-6xl mx-auto">
<div class="mb-md flex gap-2 border-b border-mono-100" role="tablist">
    <button type="button" role="tab" wire:click="showTab('nova')" aria-selected="{{ $tab === 'nova' ? 'true' : 'false' }}" class="px-3 py-2 text-sm font-semibold {{ $tab === 'nova' ? 'border-b-2 border-primary-500 text-primary-600' : 'text-mono-600' }}">Nova transmissão</button>
    <button type="button" role="tab" wire:click="showTab('historico')" aria-selected="{{ $tab === 'historico' ? 'true' : 'false' }}" class="px-3 py-2 text-sm font-semibold {{ $tab === 'historico' ? 'border-b-2 border-primary-500 text-primary-600' : 'text-mono-600' }}">Histórico</button>
    <button type="button" role="tab" wire:click="showTab('nao-receber')" aria-selected="{{ $tab === 'nao-receber' ? 'true' : 'false' }}" class="px-3 py-2 text-sm font-semibold {{ $tab === 'nao-receber' ? 'border-b-2 border-primary-500 text-primary-600' : 'text-mono-600' }}">Não receber</button>
</div>
@if ($tab === 'historico')
    <livewire:broadcasts.history />
@elseif ($tab === 'nao-receber')
    <livewire:broadcasts.suppressions />
@else
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
                    <label class="flex items-center gap-2 text-sm text-mono-700"><input type="checkbox" wire:model.live="includeOptOut"> Incluir orientação sobre saída da lista</label>
                    <p class="text-xs text-mono-600">Acrescenta no fim: "{{ \App\Support\BroadcastComposer::OPT_OUT_FOOTER }}". Desligar o aviso não tira ninguém da lista "Não receber".</p>
                </div>

                <fieldset class="rounded-2xl border border-mono-100 p-3">
                    <legend class="px-1 text-sm font-medium text-mono-700">Quando enviar</legend>
                    <div class="flex flex-wrap gap-3 text-sm">
                        <label class="flex items-center gap-1"><input type="radio" wire:model.live="sendMode" value="now"> Agora</label>
                        <label class="flex items-center gap-1"><input type="radio" wire:model.live="sendMode" value="once"> Em uma data e horário</label>
                        <label class="flex items-center gap-1"><input type="radio" wire:model.live="sendMode" value="weekly"> Toda semana (alternando)</label>
                    </div>
                    @if ($sendMode !== 'now')
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <x-jr.input :label="$sendMode === 'weekly' ? 'Primeiro envio (segunda, terça ou quarta) *' : 'Data *'" name="scheduledDate" type="date" wire:model.live="scheduledDate" min="{{ now('America/Fortaleza')->format('Y-m-d') }}" />
                            @if ($sendMode === 'once')
                                <x-jr.input label="Horário (08:00 a 20:00) *" name="scheduledTime" type="time" min="08:00" max="20:00" wire:model.live="scheduledTime" />
                            @else
                                <div>
                                    <label for="weekly-period" class="mb-2 block text-sm font-medium text-mono-600">Período inicial</label>
                                    <select id="weekly-period" wire:model.live="weeklyPeriod" class="h-12 w-full rounded-pill border border-mono-200 bg-mono-white px-4 text-sm"><option value="morning">Manhã (09:00)</option><option value="afternoon">Tarde (14:00)</option></select>
                                </div>
                            @endif
                        </div>
                        @error('scheduledDate') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        <p class="mt-2 text-xs text-mono-600">Horário de Fortaleza/Imperatriz (UTC−3).@if ($sendMode === 'weekly') Uma vez por semana: o dia alterna segunda → terça → quarta e o horário alterna manhã ↔ tarde. Os contatos das etiquetas são lidos de novo a cada envio.@endif</p>
                    @endif
                </fieldset>

                <div>
                    <p class="block text-sm font-medium text-mono-700 mb-xxxs">Prévia (exatamente o texto que será enviado)</p>
                    <div class="whitespace-pre-wrap break-words rounded-2xl border border-mono-100 bg-mono-50 px-4 py-3 text-sm text-mono-900" data-broadcast-preview>{{ $preview !== '' ? $preview : '—' }}</div>
                </div>

                <div>
                    <label for="attachment" class="block text-sm font-medium text-mono-700 mb-xxxs">Imagem, vídeo ou áudio (máx. 12 MB)</label>
                    <p class="text-xs text-mono-600">Áudio: envie a gravação em MP3, M4A, AAC, WAV, OGG ou OPUS (grave no celular e anexe aqui). Um arquivo por mensagem.</p>
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
                        accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,audio/*,.mp3,.m4a,.aac,.wav,.ogg,.oga,.opus"
                        class="sr-only"
                    >
                    <div wire:loading wire:target="attachment" class="mt-1 text-xs text-mono-500">Carregando mídia...</div>
                    @if ($attachment && ! $errors->has('attachment') && str_starts_with((string) $attachment->getMimeType(), 'audio/') && method_exists($attachment, 'temporaryUrl'))
                        @php
                            try { $audioUrl = $attachment->temporaryUrl(); } catch (\Throwable $e) { $audioUrl = null; }
                        @endphp
                        @if ($audioUrl)<audio controls preload="none" src="{{ $audioUrl }}" class="mt-2 w-full" data-audio-preview></audio>@endif
                    @endif
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

        @if ($lastBatch)
            <div data-last-batch @if (! in_array($lastBatch->status, ['done', 'done_with_errors'], true)) wire:poll.20s @endif><x-fx.card class="mt-md">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-mono-900">Último envio</h3>
                    <span class="text-xs text-mono-600">{{ \App\Domains\Broadcasts\Models\TransmissionBatch::STATUS_LABELS[$lastBatch->status] ?? $lastBatch->status }} · {{ $lastBatch->created_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }}</span>
                </div>
                <p class="mt-1 text-xs text-mono-600">{{ implode(', ', (array) $lastBatch->labels) }} · {{ $lastBatch->recipients_count }} pessoa(s) · {{ $lastBatch->duplicates_omitted }} repetido(s) e {{ $lastBatch->invalid_omitted }} sem telefone válido fora</p>
                <dl class="mt-2 grid grid-cols-2 gap-1 text-sm sm:grid-cols-5">
                    @foreach (\App\Domains\Broadcasts\Models\TransmissionRecipient::STATUSES as $k => $l)
                        <div><dt class="text-xs text-mono-600">{{ $l }}</dt><dd class="font-semibold" data-count-{{ $k }}>{{ $lastCounts[$k] ?? 0 }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-2 text-xs text-mono-600">"Aceita pelo Chatwoot" não confirma entrega ou leitura no WhatsApp. "Sem confirmação" não é reenviada automaticamente.@if ($lastBatch->media_removed_at) Anexo removido após a finalização.@endif</p>
            </x-fx.card></div>
        @endif

        @if ($reviewing)
            <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="review-title" data-broadcast-review>
                <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-mono-white p-5 shadow-elevated">
                    <h3 id="review-title" class="text-lg font-bold text-mono-900">Revisar envio</h3>
                    <p class="mt-1 text-sm text-mono-600">Etiquetas: <strong>{{ implode(', ', $selectedLabels) }}</strong>. Cada telefone recebe uma única vez, mesmo estando em mais de uma etiqueta.</p>
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <dt class="text-mono-600">Vão receber (pessoas únicas)</dt><dd class="text-right font-semibold" data-review-total>{{ $audience['unique'] }}</dd>
                        <dt class="text-mono-600">Contatos nas etiquetas (soma)</dt><dd class="text-right">{{ $audience['raw'] }}</dd>
                        <dt class="text-mono-600">Repetidos, não enviados de novo</dt><dd class="text-right" data-review-dups>{{ $audience['duplicates'] }}</dd>
                        <dt class="text-mono-600">Sem telefone (fora)</dt><dd class="text-right">{{ $audience['missing_phone'] }}</dd>
                        <dt class="text-mono-600">Telefone inválido (fora)</dt><dd class="text-right">{{ $audience['invalid_phone'] }}</dd>
                        <dt class="text-mono-600">Na lista "Não receber" (fora)</dt><dd class="text-right" data-review-suppressed>{{ $audience['suppressed'] ?? 0 }}</dd>
                    </dl>
                    @if ($attachment)<p class="mt-2 text-sm">Mídia: {{ $attachment->getClientOriginalName() }}</p>@endif
                    <p class="mt-3 text-sm font-medium text-mono-700">Mensagem</p>
                    <div class="mt-1 whitespace-pre-wrap break-words rounded-xl border border-mono-100 bg-mono-50 px-3 py-2 text-sm">{{ $preview }}</div>
                    @if ($sendMode !== 'now')<p class="mt-3 rounded-xl bg-mono-50 px-3 py-2 text-sm" data-review-schedule>{{ $sendMode === 'weekly' ? 'Toda semana, começando em' : 'Agendada para' }} <strong>{{ $firstRunLabel }}</strong> (Fortaleza). Os números acima são de agora; na hora do envio as etiquetas são lidas de novo e a lista "Não receber" é aplicada.</p>@endif
                    <p class="mt-3 rounded-xl bg-down-bg px-3 py-2 text-sm text-down">Depois de confirmar, o envio começa em segundo plano, em ordem aleatória, com 45 a 90 s entre cada pessoa, e não pode ser desfeito por esta tela.</p>
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="cancelReview" class="h-10 rounded-xl border border-mono-200 px-4 text-sm">Voltar e editar</button>
                        <button type="button" wire:click="confirmSend" wire:loading.attr="disabled" wire:target="confirmSend" class="h-10 rounded-xl bg-primary-500 px-4 text-sm font-semibold text-white disabled:opacity-60" data-confirm-send>{{ $sendMode === 'now' ? 'Confirmar e iniciar envio' : 'Confirmar agendamento' }}</button>
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
                <p class="block text-sm font-medium text-mono-700 mb-xxxs">Etiquetas do Chatwoot</p>
                <p class="mb-2 text-xs text-mono-600">Toque para marcar quem recebe. Nenhuma vem marcada.</p>
                <div class="flex flex-col gap-xxs" data-label-cards>
                    @forelse ($labels as $label)
                        @php($on = in_array($label['title'], $selectedLabels, true))
                        <button type="button" wire:click="toggleLabel(@js($label['title']))" wire:loading.attr="disabled" wire:target="toggleLabel" aria-pressed="{{ $on ? 'true' : 'false' }}"
                            class="flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-left text-sm {{ $on ? 'border-primary-500 bg-primary-100 text-primary-500' : 'border-mono-200 text-mono-900' }}">
                            <span class="flex items-center gap-2"><span class="material-icons-outlined text-[18px]">{{ $on ? 'check_box' : 'check_box_outline_blank' }}</span>{{ $label['title'] }}</span>
                            <span class="text-xs">{{ array_key_exists($label['title'], $labelCounts) ? $labelCounts[$label['title']].' contato(s)' : '' }}{{ $on ? ' · Selecionado' : '' }}</span>
                        </button>
                    @empty
                        <p class="text-sm text-mono-500 italic">{{ $loadingLabels ? 'Carregando etiquetas…' : 'Nenhuma etiqueta encontrada.' }}</p>
                    @endforelse
                </div>
                @if ($selectedLabels)
                    <button type="button" wire:click="reloadSelected" class="mt-2 text-xs text-mono-600 underline">Recarregar contatos das etiquetas marcadas</button>
                @endif
            </div>
            <div>
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
                <div class="text-sm text-mono-500 italic py-xs">{{ $selectedLabels ? 'Nenhum contato com telefone válido.' : 'Marque uma ou mais etiquetas.' }}</div>
            @else
                <p class="text-xs text-mono-600">{{ count($contacts) }} pessoa(s) únicas vão receber{{ $audience['duplicates'] ? ' ('.$audience['duplicates'].' repetida(s) removida(s))' : '' }}.</p>
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
@endif
</div>
