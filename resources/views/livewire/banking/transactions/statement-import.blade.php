<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Models\CreditCard;
use App\Domains\Banking\Services\OfxImportService;
use App\Domains\Banking\Services\Statements\GenericStatementReader;
use App\Domains\Banking\Services\Statements\StatementImportAnalyzer;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads;

    public string $target = 'bank';
    public ?int $accountId = null;
    public ?int $cardId = null;
    public string $invoiceMonth = '';
    /** auto = cada compra na fatura do ciclo; fixed = todas na fatura escolhida. */
    public string $invoiceMode = 'auto';
    public $file = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];
    public string $source = '';
    public int $skipped = 0;
    public int $ignoredBalance = 0;
    public array $warnings = [];
    public ?string $result = null;
    public string $filter = 'all';
    /** Fatura com adicionais: final (4 dígitos) => cartão, contagem, total e escolha pendente. */
    public array $finals = [];
    public string $filterFinal = '';
    public array $invoiceSummary = [];

    public function mount(): void
    {
        $this->invoiceMonth = now()->format('Y-m');
    }

    public function analyzeFile(GenericStatementReader $reader, StatementImportAnalyzer $analyzer): void
    {
        $this->resetErrorBag();
        $this->result = null;
        $this->validate([
            'target' => 'required|in:bank,card',
            'accountId' => 'required_if:target,bank|nullable|integer',
            'cardId' => 'required_if:target,card|nullable|integer',
            'invoiceMonth' => 'required_if:target,card|nullable|date_format:Y-m',
            'file' => 'required|file|max:10240',
        ], [], ['accountId' => 'conta', 'cardId' => 'cartão', 'invoiceMonth' => 'fatura', 'file' => 'arquivo']);
        $ext = strtolower($this->file->getClientOriginalExtension());
        $head = (string) @file_get_contents($this->file->getRealPath(), false, null, 0, 2048);
        $isOfx = in_array($ext, ['ofx', 'qfx'], true) || preg_match('/OFXHEADER|<OFX>/i', $head);
        if (! $isOfx && GenericStatementReader::looksLikeHtml($head)) {
            $ext = 'html'; // extrato salvo do internet banking (.html, .htm ou .bin): detectado pelo conteúdo
        }
        if (! $isOfx && ! in_array($ext, ['csv', 'txt', 'xlsx', 'pdf', 'html', 'htm', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $this->addError('file', 'Use OFX, CSV, XLSX, PDF, HTML, JPG ou PNG.');

            return;
        }
        try {
            $read = $isOfx ? $this->readOfx(app(OfxImportService::class)) : $reader->read($this->file->getRealPath(), $ext);
        } catch (\Throwable $e) {
            $this->addError('file', $e instanceof \RuntimeException ? $e->getMessage() : 'Não consegui ler este arquivo.');

            return;
        }
        $targetId = $this->target === 'card' ? (int) $this->cardId : (int) $this->accountId;
        $this->rows = $analyzer->analyze($read['rows'], $this->target, $targetId);
        $this->source = $read['source'];
        $this->skipped = $read['skipped'];
        $this->ignoredBalance = $analyzer->ignored;
        $this->warnings = $read['warnings'];
        $this->filter = 'all';
        $this->invoiceSummary = $read['summary'] ?? [];
        $this->finals = [];
        $this->filterFinal = '';
        if ($this->target === 'card') {
            $card = CreditCard::find($this->cardId);
            if ($card && $card->isAdditional() && $card->parent) {
                $this->cardId = $card->parent_card_id; // adicional não tem fatura própria: vai tudo para a do principal
                $this->warnings[] = 'Cartão escolhido é adicional: a fatura é a do principal ('.$card->parent->name.').';
            }
            $this->resolveFinals();
            $this->suggestInvoices();
        }
    }

    public function updatedInvoiceMode(): void
    {
        if ($this->target === 'card' && $this->rows) {
            $this->suggestInvoices();
        }
    }

    public function updatedInvoiceMonth(): void
    {
        if ($this->target === 'card' && $this->rows) {
            $this->suggestInvoices();
        }
    }

    private function suggestInvoices(): void
    {
        foreach (array_keys($this->rows) as $k) {
            $this->suggestInvoiceFor((int) $k);
        }
    }

    private function suggestInvoiceFor(int $k): void
    {
        $card = CreditCard::find($this->cardId);
        if (! $card || empty($this->rows[$k]['date'])) {
            return;
        }
        try {
            $date = \Illuminate\Support\Carbon::parse($this->rows[$k]['date']);
        } catch (\Throwable $e) {
            return;
        }
        $suggested = app(\App\Domains\Banking\Services\InvoiceService::class)->referenceMonthForPurchase($card, $date);
        $this->rows[$k]['suggested_invoice_month'] = $suggested;
        if ($this->invoiceMode === 'fixed' && $this->invoiceMonth) {
            $this->rows[$k]['invoice_month'] = $this->invoiceMonth;
        } elseif (empty($this->rows[$k]['invoice_month'])) {
            $this->rows[$k]['invoice_month'] = $suggested;
        }
        $this->rows[$k]['invoice_mismatch'] = ($this->rows[$k]['invoice_month'] ?? '') !== $suggested;
    }

    /** Associa cada "Final Cartão" ao principal, a um adicional dele ou pede para escolher (final desconhecido). */
    private function resolveFinals(): void
    {
        $principal = CreditCard::with('additionals')->find($this->cardId);
        if (! $principal) {
            return;
        }
        $finals = [];
        foreach ($this->rows as $k => $r) {
            $f = $r['card_final'] ?? null;
            if (! $f) {
                continue;
            }
            $finals[$f] ??= ['count' => 0, 'total' => 0.0];
            if (($r['status'] ?? '') !== 'payment') {
                $finals[$f]['count']++;
                $finals[$f]['total'] += ($r['card_kind'] ?? '') === 'refund' ? -abs((float) $r['signed']) : abs((float) $r['signed']);
            }
        }
        ksort($finals);
        foreach ($finals as $f => $info) {
            $prev = $this->finals[$f] ?? [];
            $state = 'unknown';
            $cardId = null;
            if ((string) $principal->last_four === (string) $f) {
                [$state, $cardId] = ['principal', $principal->id];
            } elseif ($add = $principal->additionals->firstWhere('last_four', $f)) {
                [$state, $cardId] = ['additional', $add->id];
            } elseif ($other = CreditCard::where('last_four', $f)->where('id', '!=', $principal->id)->first()) {
                [$state, $cardId] = ['other', $other->id];
            }
            $this->finals[$f] = ['count' => $info['count'], 'total' => round($info['total'], 2), 'state' => $state, 'card_id' => $cardId,
                'choice' => $prev['choice'] ?? ($state === 'other' ? 'card:'.$cardId : ''), 'new_name' => $prev['new_name'] ?? '', 'new_holder' => $prev['new_holder'] ?? null];
        }
        foreach ($this->rows as $k => $r) {
            $f = $r['card_final'] ?? null;
            $this->rows[$k]['additional_card_id'] = $f && in_array($this->finals[$f]['state'] ?? '', ['additional'], true) ? $this->finals[$f]['card_id'] : null;
        }
    }

    /** Responde "de quem é o final X": cartão existente vira adicional deste principal, ou cria um adicional. Fica memorizado no cartão. */
    public function assignFinal(string $final): void
    {
        $this->resetErrorBag();
        $principal = CreditCard::find($this->cardId);
        $info = $this->finals[$final] ?? null;
        if (! $principal || ! $info || ! preg_match('/^\d{4}$/', $final)) {
            return;
        }
        $choice = (string) ($info['choice'] ?? '');
        if (str_starts_with($choice, 'card:')) {
            $card = CreditCard::find((int) substr($choice, 5));
            if (! $card) {
                return;
            }
            if ($card->id !== $principal->id) {
                if ($card->additionals()->exists()) {
                    $this->addError('finals', $card->name.' tem adicionais próprios e não pode virar adicional.');

                    return;
                }
                $card->update(['parent_card_id' => $principal->id, 'card_type' => $card->card_type === 'virtual' ? 'virtual' : 'adicional', 'last_four' => $card->last_four ?: $final]);
            } elseif (! $principal->last_four) {
                $principal->update(['last_four' => $final]);
            }
        } elseif ($choice === 'new') {
            $name = trim((string) ($info['new_name'] ?? '')) ?: trim($principal->name).' adicional '.$final;
            CreditCard::create([
                'name' => mb_substr($name, 0, 120), 'last_four' => $final, 'card_type' => 'adicional', 'parent_card_id' => $principal->id,
                'card_holder_id' => ($info['new_holder'] ?? null) ? (int) $info['new_holder'] : null,
                'brand' => $principal->brand, 'bank' => $principal->bank, 'limit' => $principal->limit,
                'closing_day' => $principal->closing_day, 'due_day' => $principal->due_day,
                'default_payment_account_id' => $principal->default_payment_account_id, 'status' => true,
            ]);
        } else {
            $this->addError('finals', 'Escolha de quem é o final '.$final.'.');

            return;
        }
        $this->resolveFinals();
    }

    /** OFX entra na mesma revisão: mesmo formato de linha (data, descrição, valor com sinal) e FITID do banco. */
    private function readOfx(OfxImportService $ofx): array
    {
        $parsed = $ofx->parse((string) file_get_contents($this->file->getRealPath()));
        if ($parsed['statement_type'] !== $this->target) {
            throw new \RuntimeException($parsed['statement_type'] === 'card' ? 'Este OFX é de cartão: escolha Cartão (fatura).' : 'Este OFX é de conta: escolha Conta (extrato).');
        }
        $rows = array_map(fn ($t) => ['date' => $t['date'], 'description' => $t['description'], 'fitid' => $t['fitid'],
            'amount' => ($t['type'] === 'expense' ? -1 : 1) * (float) $t['amount']], $parsed['transactions']);

        return ['rows' => $rows, 'source' => 'OFX', 'skipped' => 0, 'warnings' => []];
    }

    /** Ao trocar tipo ou conta da transferência, procura de novo a contrapartida já lançada. */
    public function updatedRows($value, string $key): void
    {
        if ($this->target === 'card' && preg_match('/^(\d+)\.(date|invoice_month)$/', $key, $m) && isset($this->rows[(int) $m[1]])) {
            $this->suggestInvoiceFor((int) $m[1]);
        }
        if (! preg_match('/^(\d+)\.(type|transfer_account_id)$/', $key, $m) || ! isset($this->rows[(int) $m[1]])) {
            return;
        }
        $k = (int) $m[1];
        $r = &$this->rows[$k];
        $r['transfer_link_id'] = null;
        if ($r['type'] !== 'transfer') {
            if ($r['status'] === 'transfer') {
                $r['note'] = '';
            }

            return;
        }
        $r['category_id'] = null;
        if (! empty($r['transfer_account_id'])) {
            $c = StatementImportAnalyzer::findCounterpart((int) $this->accountId, (int) $r['transfer_account_id'], (float) $r['signed'], (string) $r['date']);
            $r['transfer_link_id'] = $c?->id;
            $r['note'] = $c ? 'Já existe a '.($r['signed'] < 0 ? 'entrada' : 'saída').' de mesmo valor nessa conta em '.$c->date->format('d/m').': será vinculada, sem duplicar.' : 'Será criado o par de transferência (saída e entrada).';
        }
    }

    public function selectAll(bool $on): void
    {
        foreach ($this->rows as $k => $r) {
            if (! in_array($r['status'], ['exists', 'payment'], true)) {
                $this->rows[$k]['selected'] = $on;
            }
        }
    }

    public function cancelReview(): void
    {
        $this->reset('rows', 'source', 'skipped', 'ignoredBalance', 'warnings', 'file', 'result', 'finals', 'filterFinal', 'invoiceSummary');
    }

    public function confirmImport(OfxImportService $ofx): void
    {
        $this->resetErrorBag();
        $chosen = array_values(array_filter($this->rows, fn ($r) => ! empty($r['selected']) && ! in_array($r['status'], ['exists', 'payment'], true)));
        if ($chosen === []) {
            $this->addError('rows', 'Nenhuma linha aprovada.');

            return;
        }
        $pending = array_keys(array_filter($this->finals, fn ($f) => in_array($f['state'], ['other', 'unknown'], true)));
        if ($this->target === 'card' && $pending) {
            $this->addError('rows', 'Diga de quem é o cartão final '.implode(', ', $pending).' antes de gravar.');

            return;
        }
        $payload = [];
        $transfers = [];
        foreach ($chosen as $r) {
            $amount = number_format(abs(\App\Support\Money::toFloat((string) $r['amount'])), 2, '.', '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['date']) || (float) $amount <= 0) {
                $this->addError('rows', 'Linha com data ou valor inválido: '.$r['description']);

                return;
            }
            if ($this->target === 'bank' && ($r['type'] ?? '') === 'transfer') {
                if (empty($r['transfer_account_id']) || (int) $r['transfer_account_id'] === (int) $this->accountId) {
                    $this->addError('rows', 'Escolha a outra conta da transferência: '.$r['description']);

                    return;
                }
                $transfers[] = ['fitid' => $r['fitid'], 'original_fitid' => null, 'date' => $r['date'], 'amount' => $amount, 'type' => 'transfer',
                    'signed' => (float) $r['signed'], 'transfer_account_id' => (int) $r['transfer_account_id'],
                    'description' => (string) $r['description'], 'notes' => 'Importado de extrato ('.$this->source.'): '.mb_substr((string) $r['description'], 0, 200)];

                continue;
            }
            $payload[] = [
                'fitid' => $r['fitid'], 'original_fitid' => null, 'date' => $r['date'], 'amount' => $amount,
                'type' => $r['type'], 'description' => mb_substr(trim((string) $r['description']) ?: 'Sem descrição', 0, 255),
                'notes' => 'Importado de extrato ('.$this->source.')', 'category_id' => $r['category_id'] ? (int) $r['category_id'] : null,
                'card_kind' => $r['card_kind'],
                'additional_card_id' => $r['additional_card_id'] ?? null,
                'invoice_month' => $this->target === 'card' ? ($r['invoice_month'] ?? null) : null,
            ];
        }
        try {
            $tr = ['created' => 0, 'linked' => 0, 'skipped' => 0];
            \Illuminate\Support\Facades\DB::transaction(function () use ($ofx, $payload, $transfers, &$res, &$tr) {
                if ($this->target === 'card') {
                    $month = $this->invoiceMode === 'fixed' ? $this->invoiceMonth : null;
                    $res = $ofx->importCard(CreditCard::findOrFail($this->cardId), $month, $payload, [
                        'filename' => $this->file?->getClientOriginalName(),
                        'source_label' => $this->source,
                        'invoice_mode' => $this->invoiceMode,
                        'user_id' => auth()->id(),
                    ]);
                } else {
                    $account = BankAccount::findOrFail($this->accountId);
                    $res = $payload ? $ofx->import($account, $payload) : ['imported' => 0, 'skipped' => 0];
                    if ($transfers) {
                        $tr = $ofx->importTransfers($account, $transfers);
                    }
                }
            });
        } catch (\InvalidArgumentException $e) {
            $this->addError('rows', $e->getMessage());

            return;
        }
        $this->reset('rows', 'source', 'skipped', 'ignoredBalance', 'warnings', 'file', 'finals', 'filterFinal', 'invoiceSummary');
        $this->result = $res['imported'].' lançamento(s) gravado(s). '
            .($tr['created'] ? $tr['created'].' transferência(s) criada(s) (saída e entrada). ' : '')
            .($tr['linked'] ? $tr['linked'].' transferência(s) vinculada(s) à contrapartida já lançada. ' : '')
            .(($res['skipped'] + $tr['skipped']) ? ($res['skipped'] + $tr['skipped']).' já existiam e foram ignorados.' : '');
    }

    public function with(): array
    {
        $counts = collect($this->rows)->countBy('status')->all();

        return [
            'accounts' => BankAccount::query()->orderBy('name')->get(['id', 'name']),
            'cards' => CreditCard::query()->orderBy('name')->get(['id', 'name']),
            'otherAccounts' => BankAccount::query()->where('status', true)->where('id', '!=', (int) $this->accountId)->orderBy('name')->get(['id', 'name']),
            'incomeCategories' => Category::active()->where('type', 'income')->orderBy('name')->get(['id', 'name']),
            'expenseCategories' => Category::active()->where('type', 'expense')->orderBy('name')->get(['id', 'name']),
            'counts' => $counts,
            'finalCards' => $this->finals ? CreditCard::with(['parent', 'holder'])->whereIn('id', array_filter(array_column($this->finals, 'card_id')))->get()->keyBy('id') : collect(),
            'principalCard' => $this->target === 'card' ? CreditCard::with('additionals')->find($this->cardId) : null,
            'allCards' => CreditCard::orderBy('name')->get(['id', 'name', 'last_four', 'parent_card_id']),
            'holders' => \App\Domains\Banking\Models\CardHolder::orderBy('name')->get(['id', 'name']),
            'importSum' => round(collect($this->rows)->reject(fn ($r) => ($r['status'] ?? '') === 'payment')->sum(fn ($r) => ($r['card_kind'] ?? '') === 'refund' ? -abs((float) $r['signed']) : abs((float) $r['signed'])), 2),
            'selectedCount' => count(array_filter($this->rows, fn ($r) => ! empty($r['selected']) && ! in_array($r['status'], ['exists', 'payment'], true))),
        ];
    }
}; ?>

<x-slot name="header">Importar extrato ou fatura</x-slot>

@php
    $statusLabels = ['new' => ['Nova', '#16a34a'], 'exists' => ['Já existe', '#6b7280'], 'duplicate' => ['Possível duplicata', '#ca8a04'], 'transfer' => ['Transferência', '#2563eb'], 'transfer_hint' => ['Parece transferência', '#2563eb'], 'payment' => ['Pagamento de fatura', '#6b7280']];
@endphp

<div class="flex flex-col gap-4" data-statement-import>
    <div>
        <a href="{{ route('banking.transactions.index') }}" class="text-sm text-primary-600">← Lançamentos</a>
        <h2 class="mt-2 text-2xl font-bold tracking-tight">Importar extrato ou fatura</h2>
        <p class="text-sm text-mono-600">OFX, CSV, XLSX (qualquer layout, com ou sem cabeçalho), PDF com texto ou foto: o formato é detectado sozinho. Até 10 MB. Nada é gravado até você aprovar as linhas e clicar em <strong>Gravar aprovados</strong>.</p>
    </div>

    @if ($result)<x-jr.alert variant="success">{{ $result }}</x-jr.alert>@endif

    @if ($rows === [])
        <x-jr.card>
            <form wire:submit="analyzeFile" class="grid gap-3 md:grid-cols-2">
                <div class="md:col-span-2 flex gap-2" role="group">
                    <button type="button" wire:click="$set('target', 'bank')" class="h-10 rounded-pill px-4 text-sm font-semibold {{ $target === 'bank' ? 'bg-mono-900 text-mono-white' : 'border border-mono-200' }}">Conta (extrato)</button>
                    <button type="button" wire:click="$set('target', 'card')" class="h-10 rounded-pill px-4 text-sm font-semibold {{ $target === 'card' ? 'bg-mono-900 text-mono-white' : 'border border-mono-200' }}">Cartão (fatura)</button>
                </div>
                @if ($target === 'bank')
                    <label class="text-sm">Conta *
                        <select wire:model="accountId" class="mt-1 h-11 w-full rounded-xl border border-mono-200 px-3"><option value="">Escolha…</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                        @error('accountId')<span class="text-xs text-error">{{ $message }}</span>@enderror
                    </label>
                @else
                    <label class="text-sm">Cartão *
                        <select wire:model="cardId" class="mt-1 h-11 w-full rounded-xl border border-mono-200 px-3"><option value="">Escolha…</option>@foreach ($cards as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                        @error('cardId')<span class="text-xs text-error">{{ $message }}</span>@enderror
                    </label>
                    <div class="text-sm">
                        <p class="font-medium">Fatura de cada compra</p>
                        <label class="mt-1 flex items-start gap-2">
                            <input type="radio" wire:model.live="invoiceMode" value="auto" class="mt-1">
                            <span>Automática, pela data da compra e pelo ciclo do cartão (recomendado)</span>
                        </label>
                        <label class="mt-1 flex items-start gap-2">
                            <input type="radio" wire:model.live="invoiceMode" value="fixed" class="mt-1">
                            <span>Usar esta fatura para todas:</span>
                        </label>
                        <input type="month" wire:model.live="invoiceMonth" class="mt-1 h-11 w-full rounded-xl border border-mono-200 px-3" @disabled($invoiceMode !== 'fixed')>
                        <p class="mt-1 text-xs text-mono-600">Se a data de uma compra não combinar com a fatura, o sistema avisa na revisão. Você pode manter ou trocar.</p>
                        @error('invoiceMonth')<span class="text-xs text-error">{{ $message }}</span>@enderror
                    </div>
                @endif
                <label class="text-sm md:col-span-2">Arquivo *
                    <input type="file" wire:model="file" accept=".ofx,.qfx,.csv,.txt,.xlsx,.pdf,.html,.htm,.bin,.jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm">
                    <span class="text-xs text-mono-600">OFX, CSV, XLSX, PDF, JPG ou PNG · até 10 MB. Foto precisa da leitura por IA configurada.</span>
                    @error('file')<span class="block text-xs text-error">{{ $message }}</span>@enderror
                </label>
                <div class="md:col-span-2 flex justify-end">
                    <button type="submit" class="h-11 rounded-pill bg-primary-500 px-5 text-sm font-semibold text-mono-white" wire:loading.attr="disabled" data-analyze>Ler e revisar</button>
                </div>
            </form>
        </x-jr.card>
    @else
        <x-jr.card>
            <div class="flex flex-wrap items-center gap-2 text-sm" data-review-summary>
                <span class="font-semibold">{{ count($rows) }} linha(s) lidas</span><span class="text-mono-600">· {{ $source }}@if ($skipped) · {{ $skipped }} linha(s) sem data/valor ignoradas @endif @if ($ignoredBalance) · {{ $ignoredBalance }} linha(s) de saldo/valor zero ignoradas @endif</span>
                @foreach ($statusLabels as $sk => [$sl, $col])
                    @if (($counts[$sk] ?? 0) > 0)<span class="rounded-pill px-2 py-0.5 text-xs font-semibold" style="color: {{ $col }}; border: 1px solid {{ $col }};">{{ $sl }}: {{ $counts[$sk] }}</span>@endif
                @endforeach
            </div>
            @foreach ($warnings as $w)<p class="mt-1 text-xs text-mono-600">{{ $w }}</p>@endforeach
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="selectAll(true)" class="h-9 rounded-xl border border-mono-200 px-3 text-xs">Aprovar todas</button>
                <button type="button" wire:click="selectAll(false)" class="h-9 rounded-xl border border-mono-200 px-3 text-xs">Desmarcar todas</button>
                <select wire:model.live="filter" class="h-9 rounded-xl border border-mono-200 px-2 text-xs"><option value="all">Mostrar todas</option>@foreach ($statusLabels as $sk => [$sl])<option value="{{ $sk }}">Só: {{ $sl }}</option>@endforeach</select>
            </div>
        </x-jr.card>

        @if ($target === 'card' && $principalCard)
            <x-jr.card>
                <div data-invoice-check>
                    @php $prevBal = $invoiceSummary['previous_balance'] ?? 0; $bankTotal = $invoiceSummary['invoice_total'] ?? null; $calc = round($importSum + (float) $prevBal, 2); @endphp
                    <p class="text-sm font-semibold">Fatura de {{ $principalCard->name }}</p>
                    <div class="mt-2 text-xs">
                        <label class="mr-3"><input type="radio" wire:model.live="invoiceMode" value="auto"> Automática pelo ciclo</label>
                        <label class="mr-3"><input type="radio" wire:model.live="invoiceMode" value="fixed"> Todas nesta fatura</label>
                        <input type="month" wire:model.live="invoiceMonth" class="h-9 rounded-lg border border-mono-200 px-2" @disabled($invoiceMode !== 'fixed')>
                    </div>
                    @php $mismatchCount = collect($rows)->where('invoice_mismatch', true)->where('selected', true)->count(); @endphp
                    @if ($mismatchCount)
                        <p class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900" data-invoice-mismatch-summary>{{ $mismatchCount }} compra(s) com data que não combina com a fatura escolhida. Confira a coluna Fatura antes de gravar.</p>
                    @endif
                    <p class="text-xs text-mono-600">Lançamentos (compras − créditos, sem o pagamento): R$ {{ number_format($importSum, 2, ',', '.') }}@if ($prevBal) · saldo da fatura anterior: R$ {{ number_format($prevBal, 2, ',', '.') }}@endif · soma: <strong>R$ {{ number_format($calc, 2, ',', '.') }}</strong></p>
                    @if ($bankTotal !== null)
                        <p class="text-xs {{ abs($calc - $bankTotal) < 0.01 ? 'text-up' : 'text-down' }}" data-invoice-match="{{ abs($calc - $bankTotal) < 0.01 ? '1' : '0' }}">Total da fatura no banco: R$ {{ number_format($bankTotal, 2, ',', '.') }} — {{ abs($calc - $bankTotal) < 0.01 ? 'bate' : 'diferença de R$ '.number_format($calc - $bankTotal, 2, ',', '.') }}</p>
                    @endif
                </div>
                @if ($finals)
                    <div class="mt-3 flex flex-col gap-2" data-finals>
                        <p class="text-sm font-semibold">Cartões nesta fatura (por final)</p>
                        @error('finals')<p class="text-xs text-error">{{ $message }}</p>@enderror
                        @foreach ($finals as $f => $info)
                            <div class="rounded-xl border border-mono-200 p-2 text-xs" data-final="{{ $f }}" data-final-state="{{ $info['state'] }}">
                                <span class="font-semibold">Final {{ $f }}</span> · {{ $info['count'] }} lançamento(s) · R$ {{ number_format($info['total'], 2, ',', '.') }} ·
                                @if ($info['state'] === 'principal') <span class="text-up">principal ({{ $principalCard->name }})</span>
                                @elseif ($info['state'] === 'additional') @php $fc = $finalCards[$info['card_id']] ?? null; @endphp <span class="text-up">{{ $fc ? mb_strtolower($fc->typeLabel()) : 'vinculado' }}: {{ $fc?->name }} · titular {{ $fc?->holderLabel() }}</span>
                                @else
                                    <span class="text-down">{{ $info['state'] === 'other' ? 'é de '.($finalCards[$info['card_id']]->name ?? '').', que não está como adicional deste cartão' : 'final desconhecido' }}: de quem é?</span>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <select wire:model.live="finals.{{ $f }}.choice" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" data-final-choice>
                                            <option value="">Escolha…</option>
                                            @foreach ($allCards->where('id', '!=', $principalCard->id) as $oc)<option value="card:{{ $oc->id }}">{{ $oc->name }}{{ $oc->parent_card_id === $principalCard->id ? '' : ' (tornar adicional deste)' }}</option>@endforeach
                                            <option value="new">+ Criar adicional novo</option>
                                        </select>
                                        @if (($info['choice'] ?? '') === 'new')
                                            <input type="text" wire:model="finals.{{ $f }}.new_name" placeholder="Nome (ex.: BTG Luciane {{ $f }})" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" data-final-new-name>
                                            <select wire:model="finals.{{ $f }}.new_holder" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" data-final-new-holder><option value="">Titular…</option>@foreach ($holders as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select>
                                        @endif
                                        <button type="button" wire:click="assignFinal('{{ $f }}')" class="h-9 rounded-lg bg-primary-500 px-3 text-xs font-semibold text-white" data-final-save>Confirmar</button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        <select wire:model.live="filterFinal" class="h-9 w-56 rounded-xl border border-mono-200 px-2 text-xs" aria-label="Filtrar por final"><option value="">Todos os finais</option>@foreach ($finals as $f => $info)<option value="{{ $f }}">Só final {{ $f }}</option>@endforeach</select>
                    </div>
                @endif
            </x-jr.card>
        @endif

        <div class="flex flex-col gap-2">
            @foreach ($rows as $k => $r)
                @continue($filter !== 'all' && $r['status'] !== $filter)
                @continue($filterFinal !== '' && ($r['card_final'] ?? '') !== $filterFinal)
                @php [$sl, $col] = $statusLabels[$r['status']]; $locked = in_array($r['status'], ['exists', 'payment'], true); @endphp
                <div class="rounded-xl border bg-mono-white p-3 text-sm {{ $locked ? 'opacity-60' : '' }}" style="border-color: {{ $col }};" wire:key="row-{{ $r['fitid'] }}" data-row-status="{{ $r['status'] }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="checkbox" wire:model.live="rows.{{ $k }}.selected" @disabled($locked) aria-label="Aprovar linha">
                        <span class="rounded-pill px-2 py-0.5 text-xs font-semibold" style="color: {{ $col }}; border: 1px solid {{ $col }};">{{ $sl }}</span>
                        @php $neg = ($r['type'] ?? '') === 'transfer' ? $r['signed'] < 0 : ($r['type'] ?? '') === 'expense'; $acctName = ($r['type'] ?? '') === 'transfer' ? $otherAccounts->firstWhere('id', (int) ($r['transfer_account_id'] ?? 0))?->name : null; @endphp
                        <span class="font-semibold {{ $neg ? 'text-down' : 'text-up' }}" data-row-amount>{{ $neg ? '−' : '+' }} R$ {{ number_format(\App\Support\Money::toFloat((string) $r['amount']), 2, ',', '.') }}</span>
                        @if (($r['type'] ?? '') === 'transfer')<span class="text-xs text-mono-600">{{ $r['signed'] < 0 ? 'para' : 'de' }} {{ $acctName ?? '(escolha a conta)' }}</span>@endif
                        <span class="text-mono-600">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}</span>
                        @if (! empty($r['card_final']))<span class="rounded-pill border border-mono-200 px-2 py-0.5 text-xs" data-row-final>Final {{ $r['card_final'] }}{{ ! empty($r['additional_card_id']) ? ' · '.($finalCards[$r['additional_card_id']]->name ?? '') : '' }}</span>@endif
                    </div>
                    @if ($r['note'])<p class="mt-1 text-xs" style="color: {{ $col }};">{{ $r['note'] }}</p>@endif
                    @unless ($locked)
                        <div class="mt-2 grid gap-2 md:grid-cols-4">
                            <input type="date" wire:model.blur="rows.{{ $k }}.date" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" aria-label="Data">
                            <input type="text" wire:model.blur="rows.{{ $k }}.description" class="h-9 rounded-lg border border-mono-200 px-2 text-xs md:col-span-2" aria-label="Descrição">
                            <input type="text" inputmode="decimal" x-money wire:model.blur="rows.{{ $k }}.amount" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" aria-label="Valor" data-row-amount-input>
                            @if ($target === 'card')
                                <label class="text-xs md:col-span-4">Fatura
                                    <input type="month" wire:model.blur="rows.{{ $k }}.invoice_month" class="mt-1 h-9 w-full rounded-lg border px-2 {{ ! empty($r['invoice_mismatch']) ? 'border-amber-500' : 'border-mono-200' }}" aria-label="Fatura da compra" data-row-invoice>
                                    @if (! empty($r['invoice_mismatch']))
                                        <span class="text-amber-800" data-row-invoice-mismatch>A data desta compra combina com {{ isset($r['suggested_invoice_month']) ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $r['suggested_invoice_month'])->translatedFormat('M/Y') : 'outra fatura' }}.</span>
                                    @endif
                                </label>
                            @endif
                            @if ($target === 'bank')
                                <select wire:model.live="rows.{{ $k }}.type" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" aria-label="Tipo" data-row-type>
                                    <option value="expense">Despesa</option><option value="income">Receita</option><option value="transfer">Transferência</option>
                                </select>
                            @endif
                            @if (($r['type'] ?? '') === 'transfer')
                                <select wire:model.live="rows.{{ $k }}.transfer_account_id" class="h-9 rounded-lg border border-mono-200 px-2 text-xs md:col-span-3" aria-label="Outra conta" data-row-transfer-account>
                                    <option value="">{{ $r['signed'] < 0 ? 'Conta de destino…' : 'Conta de origem…' }}</option>
                                    @foreach ($otherAccounts as $oa)<option value="{{ $oa->id }}">{{ $oa->name }}</option>@endforeach
                                </select>
                            @else
                                <select wire:model="rows.{{ $k }}.category_id" class="h-9 rounded-lg border border-mono-200 px-2 text-xs md:col-span-3" aria-label="Categoria">
                                    <option value="">Sem categoria</option>
                                    @foreach ($r['type'] === 'income' ? $incomeCategories : $expenseCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                                </select>
                            @endif
                        </div>
                    @endunless
                </div>
            @endforeach
        </div>

        @error('rows')<x-jr.alert variant="error">{{ $message }}</x-jr.alert>@enderror
        <div class="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-mono-100 bg-mono-50 py-3">
            <span class="mr-auto text-sm">{{ $selectedCount }} aprovada(s) para gravar</span>
            <button type="button" wire:click="cancelReview" class="h-11 rounded-pill border border-mono-200 px-4 text-sm">Cancelar</button>
            <button type="button" wire:click="confirmImport" wire:confirm="Gravar {{ $selectedCount }} lançamento(s) aprovado(s)?" class="h-11 rounded-pill bg-primary-500 px-5 text-sm font-semibold text-mono-white" data-confirm-import>Gravar aprovados</button>
        </div>
    @endif
</div>
