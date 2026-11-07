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
    public $file = null;

    /** @var list<array<string, mixed>> */
    public array $rows = [];
    public string $source = '';
    public int $skipped = 0;
    public array $warnings = [];
    public ?string $result = null;
    public string $filter = 'all';

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
        if (! in_array($ext, ['csv', 'txt', 'xlsx', 'pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $this->addError('file', 'Use CSV, XLSX, PDF, JPG ou PNG. Para OFX, use o botão Importar OFX.');

            return;
        }
        try {
            $read = $reader->read($this->file->getRealPath(), $ext);
        } catch (\Throwable $e) {
            $this->addError('file', $e instanceof \RuntimeException ? $e->getMessage() : 'Não consegui ler este arquivo.');

            return;
        }
        $targetId = $this->target === 'card' ? (int) $this->cardId : (int) $this->accountId;
        $this->rows = $analyzer->analyze($read['rows'], $this->target, $targetId);
        $this->source = $read['source'];
        $this->skipped = $read['skipped'];
        $this->warnings = $read['warnings'];
        $this->filter = 'all';
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
        $this->reset('rows', 'source', 'skipped', 'warnings', 'file', 'result');
    }

    public function confirmImport(OfxImportService $ofx): void
    {
        $this->resetErrorBag();
        $chosen = array_values(array_filter($this->rows, fn ($r) => ! empty($r['selected']) && ! in_array($r['status'], ['exists', 'payment'], true)));
        if ($chosen === []) {
            $this->addError('rows', 'Nenhuma linha aprovada.');

            return;
        }
        $payload = [];
        foreach ($chosen as $r) {
            $amount = number_format(abs((float) str_replace(',', '.', (string) $r['amount'])), 2, '.', '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r['date']) || (float) $amount <= 0) {
                $this->addError('rows', 'Linha com data ou valor inválido: '.$r['description']);

                return;
            }
            $payload[] = [
                'fitid' => $r['fitid'], 'original_fitid' => null, 'date' => $r['date'], 'amount' => $amount,
                'type' => $r['type'], 'description' => mb_substr(trim((string) $r['description']) ?: 'Sem descrição', 0, 255),
                'notes' => 'Importado de extrato ('.$this->source.')', 'category_id' => $r['category_id'] ? (int) $r['category_id'] : null,
                'card_kind' => $r['card_kind'],
            ];
        }
        try {
            if ($this->target === 'card') {
                $res = $ofx->importCard(CreditCard::findOrFail($this->cardId), $this->invoiceMonth, $payload);
            } else {
                $res = $ofx->import(BankAccount::findOrFail($this->accountId), $payload);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addError('rows', $e->getMessage());

            return;
        }
        $this->reset('rows', 'source', 'skipped', 'warnings', 'file');
        $this->result = $res['imported'].' lançamento(s) gravado(s). '.($res['skipped'] ? $res['skipped'].' já existiam e foram ignorados.' : '');
    }

    public function with(): array
    {
        $counts = collect($this->rows)->countBy('status')->all();

        return [
            'accounts' => BankAccount::query()->orderBy('name')->get(['id', 'name']),
            'cards' => CreditCard::query()->orderBy('name')->get(['id', 'name']),
            'incomeCategories' => Category::active()->where('type', 'income')->orderBy('name')->get(['id', 'name']),
            'expenseCategories' => Category::active()->where('type', 'expense')->orderBy('name')->get(['id', 'name']),
            'counts' => $counts,
            'selectedCount' => count(array_filter($this->rows, fn ($r) => ! empty($r['selected']) && ! in_array($r['status'], ['exists', 'payment'], true))),
        ];
    }
}; ?>

<x-slot name="header">Importar extrato ou fatura</x-slot>

@php
    $statusLabels = ['new' => ['Nova', '#16a34a'], 'exists' => ['Já existe', '#6b7280'], 'duplicate' => ['Possível duplicata', '#ca8a04'], 'transfer' => ['Transferência?', '#2563eb'], 'transfer_hint' => ['Parece transferência', '#2563eb'], 'payment' => ['Pagamento de fatura', '#6b7280']];
@endphp

<div class="flex flex-col gap-4" data-statement-import>
    <div>
        <a href="{{ route('banking.transactions.index') }}" class="text-sm text-primary-600">← Lançamentos</a>
        <h2 class="mt-2 text-2xl font-bold tracking-tight">Importar extrato ou fatura</h2>
        <p class="text-sm text-mono-600">CSV, XLSX (qualquer layout, com ou sem cabeçalho), PDF com texto ou foto. Até 10 MB. Nada é gravado até você aprovar as linhas e clicar em <strong>Gravar aprovados</strong>.</p>
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
                    <label class="text-sm">Fatura (mês) *
                        <input type="month" wire:model="invoiceMonth" class="mt-1 h-11 w-full rounded-xl border border-mono-200 px-3">
                        @error('invoiceMonth')<span class="text-xs text-error">{{ $message }}</span>@enderror
                    </label>
                @endif
                <label class="text-sm md:col-span-2">Arquivo *
                    <input type="file" wire:model="file" accept=".csv,.txt,.xlsx,.pdf,.jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm">
                    <span class="text-xs text-mono-600">CSV, XLSX, PDF, JPG ou PNG · até 10 MB. Foto precisa da leitura por IA configurada.</span>
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
                <span class="font-semibold">{{ count($rows) }} linha(s) lidas</span><span class="text-mono-600">· {{ $source }}@if ($skipped) · {{ $skipped }} linha(s) sem data/valor ignoradas @endif</span>
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

        <div class="flex flex-col gap-2">
            @foreach ($rows as $k => $r)
                @continue($filter !== 'all' && $r['status'] !== $filter)
                @php [$sl, $col] = $statusLabels[$r['status']]; $locked = in_array($r['status'], ['exists', 'payment'], true); @endphp
                <div class="rounded-xl border bg-mono-white p-3 text-sm {{ $locked ? 'opacity-60' : '' }}" style="border-color: {{ $col }};" wire:key="row-{{ $r['fitid'] }}" data-row-status="{{ $r['status'] }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="checkbox" wire:model.live="rows.{{ $k }}.selected" @disabled($locked) aria-label="Aprovar linha">
                        <span class="rounded-pill px-2 py-0.5 text-xs font-semibold" style="color: {{ $col }}; border: 1px solid {{ $col }};">{{ $sl }}</span>
                        <span class="font-semibold {{ $r['signed'] < 0 ? 'text-down' : 'text-up' }}">{{ $r['signed'] < 0 ? '−' : '+' }} R$ {{ number_format((float) $r['amount'], 2, ',', '.') }}</span>
                        <span class="text-mono-600">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d/m/Y') }}</span>
                    </div>
                    @if ($r['note'])<p class="mt-1 text-xs" style="color: {{ $col }};">{{ $r['note'] }}</p>@endif
                    @unless ($locked)
                        <div class="mt-2 grid gap-2 md:grid-cols-4">
                            <input type="date" wire:model.blur="rows.{{ $k }}.date" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" aria-label="Data">
                            <input type="text" wire:model.blur="rows.{{ $k }}.description" class="h-9 rounded-lg border border-mono-200 px-2 text-xs md:col-span-2" aria-label="Descrição">
                            <input type="text" inputmode="decimal" wire:model.blur="rows.{{ $k }}.amount" class="h-9 rounded-lg border border-mono-200 px-2 text-xs" aria-label="Valor">
                            <select wire:model="rows.{{ $k }}.category_id" class="h-9 rounded-lg border border-mono-200 px-2 text-xs md:col-span-4" aria-label="Categoria">
                                <option value="">Sem categoria</option>
                                @foreach ($r['type'] === 'income' ? $incomeCategories : $expenseCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                            </select>
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
