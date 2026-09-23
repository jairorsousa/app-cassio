<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\Category;
use App\Domains\Banking\Services\OfxImportDraftService;
use App\Domains\Banking\Services\OfxImportService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public array $excludedIndices = [];

    public array $categorySelections = [];

    public function mount(OfxImportDraftService $drafts): void
    {
        $draft = $drafts->current();

        if (! $draft) {
            $this->redirectRoute('banking.transactions.index', navigate: true);

            return;
        }

        $this->categorySelections = $draft['categories'];
        $this->excludedIndices = $draft['excluded'];
    }

    public function updatedCategorySelections(): void
    {
        app(OfxImportDraftService::class)->saveReview($this->categorySelections, $this->excludedIndices);
    }

    public function exclude(int $index, OfxImportDraftService $drafts): void
    {
        $this->excludedIndices[$index] = true;
        $drafts->saveReview($this->categorySelections, $this->excludedIndices);
    }

    public function restore(int $index, OfxImportDraftService $drafts): void
    {
        unset($this->excludedIndices[$index]);
        $drafts->saveReview($this->categorySelections, $this->excludedIndices);
    }

    public function cancel(OfxImportDraftService $drafts): void
    {
        $drafts->clear();
        $this->redirectRoute('banking.transactions.index', navigate: true);
    }

    public function confirmImport(OfxImportDraftService $drafts, OfxImportService $service): void
    {
        $draft = $drafts->current();

        if (! $draft) {
            $this->redirectRoute('banking.transactions.index', navigate: true);

            return;
        }

        $account = BankAccount::active()->find($draft['account_id']);

        if (! $account) {
            $this->addError('import', 'A conta selecionada não está mais disponível.');

            return;
        }

        $rows = $service->parse($draft['contents'])['transactions'];
        $existing = $service->existingFitids($account, $rows);
        $selected = [];
        $chosenIds = [];

        foreach ($rows as $index => $row) {
            if (isset($existing[$row['fitid']]) || ! empty($this->excludedIndices[$index])) {
                continue;
            }

            $categoryId = $this->categorySelections[$index] ?? null;

            if ($categoryId !== null && $categoryId !== '') {
                if (! ctype_digit((string) $categoryId)) {
                    $this->addError('import', 'Revise as categorias selecionadas.');

                    return;
                }

                $chosenIds[] = (int) $categoryId;
                $row['category_id'] = (int) $categoryId;
            }

            $selected[] = $row;
        }

        if ($selected === []) {
            $this->addError('import', 'Selecione ao menos um lançamento novo para importar.');

            return;
        }

        $categories = Category::active()->whereIn('id', array_unique($chosenIds))->get()->keyBy('id');

        foreach ($selected as $row) {
            if (isset($row['category_id']) && $categories->get($row['category_id'])?->type !== $row['type']) {
                $this->addError('import', 'Uma categoria selecionada não corresponde ao tipo do lançamento.');

                return;
            }
        }

        $result = $service->import($account, $selected);
        $dates = array_column($selected, 'date');
        $alreadyImported = count($existing) + $result['skipped'];
        $drafts->clear();
        session()->flash('status', "OFX importado: {$result['imported']} lançamento(s) novo(s) e {$alreadyImported} já importado(s) nesta conta.");
        $this->redirectRoute('banking.transactions.index', [
            'from' => min($dates),
            'to' => max($dates),
            'account' => $account->id,
        ], navigate: true);
    }

    public function formatMoney(int $cents): string
    {
        return number_format(intdiv($cents, 100), 0, ',', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function with(OfxImportDraftService $drafts, OfxImportService $service): array
    {
        $draft = $drafts->current();

        if (! $draft) {
            return [
                'incomeRows' => [], 'expenseRows' => [], 'incomeCents' => 0,
                'expenseCents' => 0, 'selectedCount' => 0, 'duplicateCount' => 0,
                'excludedCount' => 0, 'statementAccount' => null, 'accountName' => '',
                'incomeCategories' => collect(), 'expenseCategories' => collect(),
            ];
        }

        $parsed = $service->parse($draft['contents']);
        $account = BankAccount::find($draft['account_id']);
        $existing = $account ? $service->existingFitids($account, $parsed['transactions']) : [];
        $incomeRows = $expenseRows = [];
        $incomeCents = $expenseCents = $selectedCount = $excludedCount = 0;

        foreach ($parsed['transactions'] as $index => $row) {
            $row['index'] = $index;
            $row['duplicate'] = isset($existing[$row['fitid']]);
            $row['excluded'] = ! empty($this->excludedIndices[$index]);
            $parts = explode('.', $row['amount']);
            $cents = ((int) $parts[0] * 100) + (int) $parts[1];

            if ($row['type'] === 'income') {
                $incomeRows[] = $row;
                $incomeCents += $cents;
            } else {
                $expenseRows[] = $row;
                $expenseCents += $cents;
            }

            if ($row['excluded'] && ! $row['duplicate']) {
                $excludedCount++;
            }

            if (! $row['excluded'] && ! $row['duplicate']) {
                $selectedCount++;
            }
        }

        $byDate = fn (array $a, array $b) => [$a['date'], $a['index']] <=> [$b['date'], $b['index']];
        usort($incomeRows, $byDate);
        usort($expenseRows, $byDate);

        return [
            'incomeRows' => $incomeRows,
            'expenseRows' => $expenseRows,
            'incomeCents' => $incomeCents,
            'expenseCents' => $expenseCents,
            'selectedCount' => $selectedCount,
            'duplicateCount' => count($existing),
            'excludedCount' => $excludedCount,
            'statementAccount' => $parsed['account'],
            'accountName' => $account?->name ?? 'Conta indisponível',
            'incomeCategories' => Category::active()->where('type', 'income')->orderBy('name')->get(),
            'expenseCategories' => Category::active()->where('type', 'expense')->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Prévia da importação OFX</x-slot>

<div class="flex flex-col gap-6">
    <x-banking.subnav />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('banking.transactions.index') }}" class="text-sm font-medium text-primary-500 hover:underline">Lançamentos</a>
            <span class="mx-2 text-mono-400">/</span><span class="text-sm text-mono-600">Prévia OFX</span>
            <h1 class="mt-2 text-2xl font-bold text-mono-900">Confira os lançamentos do extrato</h1>
            <p class="mt-1 text-sm text-mono-600">Conta de destino: <strong>{{ $accountName }}</strong>@if ($statementAccount) · Conta no OFX: <strong>{{ $statementAccount }}</strong>@endif</p>
        </div>
        <button type="button" wire:click="cancel" class="text-sm font-semibold text-mono-600 hover:text-mono-900">Cancelar importação</button>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="flex items-center gap-4 rounded-2xl border border-mono-100 bg-mono-white p-5 shadow-sm">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-50 text-blue-600"><span class="material-icons-outlined">receipt_long</span></span>
            <div><p class="text-sm text-mono-500">Total de transações</p><p class="text-xl font-bold text-mono-900">{{ count($incomeRows) + count($expenseRows) }}</p></div>
        </div>
        <div class="flex items-center gap-4 rounded-2xl border border-mono-100 bg-mono-white p-5 shadow-sm">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-green-50 text-green-600"><span class="material-icons-outlined">trending_up</span></span>
            <div><p class="text-sm text-mono-500">Total receitas</p><p class="text-xl font-bold text-green-600">R$ {{ $this->formatMoney($incomeCents) }}</p></div>
        </div>
        <div class="flex items-center gap-4 rounded-2xl border border-mono-100 bg-mono-white p-5 shadow-sm">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-red-600"><span class="material-icons-outlined">trending_down</span></span>
            <div><p class="text-sm text-mono-500">Total despesas</p><p class="text-xl font-bold text-red-600">R$ {{ $this->formatMoney($expenseCents) }}</p></div>
        </div>
    </div>

    <p class="text-sm text-mono-600">Os totais acima incluem todas as transações do arquivo. Lançamentos já importados nesta conta aparecem identificados e não serão cadastrados novamente. Você pode definir categorias e retirar itens da importação.</p>

    @foreach (['income' => ['title' => 'Receitas', 'rows' => $incomeRows, 'categories' => $incomeCategories, 'color' => 'green'], 'expense' => ['title' => 'Despesas', 'rows' => $expenseRows, 'categories' => $expenseCategories, 'color' => 'red']] as $type => $section)
        <section class="space-y-3">
            <div class="flex items-center gap-3">
                <span class="material-icons-outlined {{ $type === 'income' ? 'text-green-600' : 'text-red-600' }}">{{ $type === 'income' ? 'north' : 'south' }}</span>
                <h2 class="text-lg font-bold text-mono-900">{{ $section['title'] }}</h2>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $type === 'income' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">{{ count($section['rows']) }}</span>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-mono-100 bg-mono-white shadow-sm">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="border-b border-mono-100 bg-mono-50 text-left text-xs font-semibold uppercase tracking-wide text-mono-500">
                        <tr><th class="w-32 px-5 py-4">Data</th><th class="px-5 py-4">Descrição</th><th class="w-52 px-5 py-4">Categoria</th><th class="w-40 px-5 py-4 text-right">Valor</th><th class="w-14 px-3 py-4"><span class="sr-only">Ações</span></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($section['rows'] as $row)
                            <tr wire:key="ofx-row-{{ $row['index'] }}" class="border-b border-mono-100 last:border-b-0 {{ $row['duplicate'] || $row['excluded'] ? 'bg-mono-50 opacity-60' : '' }}">
                                <td class="whitespace-nowrap px-5 py-4 text-mono-500">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                                <td class="px-5 py-4 font-medium text-mono-900">
                                    {{ $row['description'] }}
                                    @if ($row['duplicate']) <span class="ml-2 rounded-full bg-blue-50 px-2 py-1 text-xs text-blue-700">Já importado</span> @endif
                                    @if ($row['excluded'] && ! $row['duplicate']) <span class="ml-2 rounded-full bg-mono-200 px-2 py-1 text-xs text-mono-600">Retirado</span> @endif
                                </td>
                                <td class="px-5 py-3">
                                    <select wire:model.change="categorySelections.{{ $row['index'] }}" aria-label="Categoria de {{ $row['description'] }}" @disabled($row['duplicate'] || $row['excluded']) class="h-10 w-full rounded-xl border border-mono-200 bg-mono-white px-3 text-sm text-mono-700 focus:border-primary-500 focus:outline-none">
                                        <option value="">Sem categoria</option>
                                        @foreach ($section['categories'] as $categoryOption)
                                            <option value="{{ $categoryOption->id }}">{{ $categoryOption->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-right font-semibold {{ $type === 'income' ? 'text-green-600' : 'text-red-600' }}">{{ $type === 'income' ? '+' : '-' }} R$ {{ number_format((float) $row['amount'], 2, ',', '.') }}</td>
                                <td class="px-3 py-4 text-center">
                                    @if (! $row['duplicate'])
                                        @if ($row['excluded'])
                                            <button type="button" wire:click="restore({{ $row['index'] }})" title="Reincluir lançamento" aria-label="Reincluir lançamento" class="text-primary-500 hover:text-primary-600"><span class="material-icons-outlined text-[20px]">undo</span></button>
                                        @else
                                            <button type="button" wire:click="exclude({{ $row['index'] }})" title="Retirar da importação" aria-label="Retirar da importação" class="text-mono-400 hover:text-red-600"><span class="material-icons-outlined text-[20px]">close</span></button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-8 text-center text-mono-500">Nenhuma {{ strtolower($section['title']) }} no arquivo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    <div class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-mono-100 bg-mono-white px-6 py-5 shadow-lg">
        <div class="text-sm text-mono-600">
            <strong class="text-mono-900">{{ $selectedCount }}</strong> transação(ões) serão importadas
            @if ($duplicateCount) <span class="ml-2">· {{ $duplicateCount }} já importada(s)</span> @endif
            @if ($excludedCount) <span class="ml-2">· {{ $excludedCount }} retirada(s)</span> @endif
        </div>
        <div class="flex items-center gap-3">
            <button type="button" wire:click="cancel" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 hover:bg-mono-200">Cancelar</button>
            <button type="button" wire:click="confirmImport" wire:loading.attr="disabled" @disabled($selectedCount === 0) class="inline-flex h-11 items-center gap-2 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white hover:bg-primary-600 disabled:opacity-50"><span class="material-icons-outlined text-[18px]">download</span>Importar transações</button>
        </div>
        @error('import') <p class="w-full text-sm text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
