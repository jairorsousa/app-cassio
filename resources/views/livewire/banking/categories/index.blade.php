<?php

use App\Domains\Banking\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $type = 'expense';
    public string $icon = 'label';
    public string $color = '#ff6f00';
    public ?int $parent_id = null;
    public bool $status = true;
    public bool $showFormModal = false;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'type' => 'required|in:income,expense',
            'icon' => 'required|string|max:32',
            'color' => 'required|string|max:9',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'boolean',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function edit(int $id): void
    {
        $cat = Category::findOrFail($id);
        $this->editingId = $cat->id;
        $this->name = $cat->name;
        $this->type = $cat->type;
        $this->icon = $this->materialIcon((string) $cat->icon, $cat->name);
        $this->color = (string) ($cat->color ?: '#ff6f00');
        $this->parent_id = $cat->parent_id;
        $this->status = (bool) $cat->status;
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            Category::find($this->editingId)?->update($data);
        } else {
            Category::create($data);
        }

        $this->resetForm();
        session()->flash('status', 'Categoria salva.');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        Category::find($id)?->delete();
        session()->flash('status', 'Categoria excluída.');
    }

    public function iconOptions(): array
    {
        return [
            'label',
            'home',
            'restaurant',
            'directions_car',
            'local_hospital',
            'school',
            'sports_esports',
            'payments',
            'checkroom',
            'pets',
            'receipt_long',
            'account_balance_wallet',
            'work',
            'trending_up',
            'currency_exchange',
            'shopping_cart',
            'local_gas_station',
            'flight',
        ];
    }

    public function colorOptions(): array
    {
        return [
            '#ff6f00',
            '#5b6ecb',
            '#ef5350',
            '#42a5f5',
            '#5dbb63',
            '#ab47bc',
            '#45bfd0',
            '#f9a825',
            '#ec407a',
            '#8d6e63',
            '#78909c',
        ];
    }

    public function softColor(?string $color): string
    {
        $color = $color ?: '#ff6f00';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color.'1A' : '#fff0e0';
    }

    public function materialIcon(?string $icon, string $name = ''): string
    {
        $map = [
            'Utensils' => 'restaurant',
            'Repeat' => 'currency_exchange',
            'GraduationCap' => 'school',
            'Handshake' => 'payments',
            'Receipt' => 'receipt_long',
            'Gamepad2' => 'sports_esports',
            'Home' => 'home',
            'HeartPulse' => 'local_hospital',
            'Car' => 'directions_car',
            'Shirt' => 'checkroom',
            'PawPrint' => 'pets',
            'Briefcase' => 'work',
            'TrendingUp' => 'trending_up',
            'Wallet' => 'payments',
            'Gift' => 'account_balance_wallet',
            'Tag' => 'label',
        ];

        if ($icon && isset($map[$icon])) {
            return $map[$icon];
        }

        return $icon ?: $this->defaultIconFor($name);
    }

    public function defaultIconFor(string $name): string
    {
        return [
            'Alimentacao' => 'restaurant',
            'Alimentação' => 'restaurant',
            'Assinaturas' => 'currency_exchange',
            'Educacao' => 'school',
            'Educação' => 'school',
            'Emprestimo' => 'payments',
            'Empréstimo' => 'payments',
            'Impostos' => 'receipt_long',
            'Lazer' => 'sports_esports',
            'Moradia' => 'home',
            'Pets' => 'pets',
            'Saude' => 'local_hospital',
            'Saúde' => 'local_hospital',
            'Transporte' => 'directions_car',
            'Vestuario' => 'checkroom',
            'Vestuário' => 'checkroom',
            'Cashback' => 'account_balance_wallet',
            'Freelance' => 'work',
            'Investimentos' => 'trending_up',
            'Salario' => 'payments',
            'Salário' => 'payments',
        ][$name] ?? 'label';
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'type', 'icon', 'color', 'parent_id', 'status', 'showFormModal']);
        $this->type = 'expense';
        $this->icon = 'label';
        $this->color = '#ff6f00';
        $this->status = true;
    }

    private function categoryRoots(string $type): Collection
    {
        return Category::query()
            ->withCount('transactions')
            ->with(['children' => fn ($query) => $query
                ->where('type', $type)
                ->withCount('transactions')
                ->orderBy('name')])
            ->roots()
            ->where('type', $type)
            ->orderBy('name')
            ->get();
    }

    public function with(): array
    {
        $expenseRoots = $this->categoryRoots('expense');
        $incomeRoots = $this->categoryRoots('income');

        return [
            'expenseRoots' => $expenseRoots,
            'incomeRoots' => $incomeRoots,
            'expenseCount' => Category::where('type', 'expense')->count(),
            'incomeCount' => Category::where('type', 'income')->count(),
            'parents' => Category::roots()->where('type', $this->type)->orderBy('name')->get(),
        ];
    }
}; ?>

<x-slot name="header">Financeiro · Categorias</x-slot>

<div class="space-y-md">
    <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-semibold text-mono-900">Categorias</h2>
        </div>

        <button type="button" class="fx-btn fx-btn--primary self-start sm:self-auto" wire:click="create">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M12 5v14"/><path d="M5 12h14"/>
            </svg>
            Nova Categoria
        </button>
    </div>

    @if (session('status'))
        <div class="fx-alert fx-alert--success">{{ session('status') }}</div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-md">
        <section class="min-w-0">
            <div class="mb-sm flex items-center gap-xs">
                <span class="h-3 w-3 rounded-full bg-down"></span>
                <h3 class="text-lg font-semibold text-mono-900">Despesas ({{ $expenseCount }})</h3>
            </div>

            <div class="flex flex-col gap-xs">
                @forelse ($expenseRoots as $category)
                    <article class="rounded-md border border-mono-100 bg-mono-white p-sm shadow-card">
                        <div class="flex items-center gap-sm">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full" style="background-color: {{ $this->softColor($category->color) }}; color: {{ $category->color ?: '#ff6f00' }}">
                                <span class="material-icons-outlined text-[22px]">{{ $this->materialIcon($category->icon, $category->name) }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-xs gap-y-xxxs">
                                    <h4 class="truncate text-sm font-semibold text-mono-900">{{ $category->name }}</h4>
                                    <span class="text-xs text-mono-600">{{ $category->transactions_count }} transações</span>
                                </div>
                                @unless ($category->status)
                                    <span class="text-xxs text-mono-600">Inativa</span>
                                @endunless
                            </div>

                            <div class="flex shrink-0 items-center gap-xxs">
                                <button type="button" class="fx-btn fx-btn--icon h-9 w-9" wire:click="edit({{ $category->id }})" title="Editar categoria" aria-label="Editar {{ $category->name }}">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/>
                                    </svg>
                                </button>
                                <button type="button" class="fx-btn fx-btn--icon h-9 w-9 text-error" wire:click="delete({{ $category->id }})" wire:confirm="Excluir esta categoria?" title="Excluir categoria" aria-label="Excluir {{ $category->name }}">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        @if ($category->children->isNotEmpty())
                            <div class="mt-xs ml-[1.35rem] flex flex-col gap-xxs border-l border-mono-100 pl-sm">
                                @foreach ($category->children as $child)
                                    <div class="flex items-center gap-sm rounded-md border border-mono-100 bg-mono-50 p-xs">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style="background-color: {{ $this->softColor($child->color) }}; color: {{ $child->color ?: '#ff6f00' }}">
                                            <span class="material-icons-outlined text-[18px]">{{ $this->materialIcon($child->icon, $child->name) }}</span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-baseline gap-x-xs gap-y-xxxs">
                                                <span class="truncate text-xs font-semibold text-mono-900">{{ $child->name }}</span>
                                                <span class="text-xxs text-mono-600">{{ $child->transactions_count }} transações</span>
                                            </div>
                                        </div>
                                        <button type="button" class="fx-btn fx-btn--icon h-8 w-8" wire:click="edit({{ $child->id }})" title="Editar categoria" aria-label="Editar {{ $child->name }}">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/>
                                            </svg>
                                        </button>
                                        <button type="button" class="fx-btn fx-btn--icon h-8 w-8 text-error" wire:click="delete({{ $child->id }})" wire:confirm="Excluir esta categoria?" title="Excluir categoria" aria-label="Excluir {{ $child->name }}">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>
                                            </svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-md border border-dashed border-mono-200 bg-mono-white p-lg text-sm text-mono-600">
                        Nenhuma categoria de despesa cadastrada.
                    </div>
                @endforelse
            </div>
        </section>

        <section class="min-w-0">
            <div class="mb-sm flex items-center gap-xs">
                <span class="h-3 w-3 rounded-full bg-up"></span>
                <h3 class="text-lg font-semibold text-mono-900">Receitas ({{ $incomeCount }})</h3>
            </div>

            <div class="flex flex-col gap-xs">
                @forelse ($incomeRoots as $category)
                    <article class="rounded-md border border-mono-100 bg-mono-white p-sm shadow-card">
                        <div class="flex items-center gap-sm">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full" style="background-color: {{ $this->softColor($category->color) }}; color: {{ $category->color ?: '#ff6f00' }}">
                                <span class="material-icons-outlined text-[22px]">{{ $this->materialIcon($category->icon, $category->name) }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-xs gap-y-xxxs">
                                    <h4 class="truncate text-sm font-semibold text-mono-900">{{ $category->name }}</h4>
                                    <span class="text-xs text-mono-600">{{ $category->transactions_count }} transações</span>
                                </div>
                                @unless ($category->status)
                                    <span class="text-xxs text-mono-600">Inativa</span>
                                @endunless
                            </div>

                            <div class="flex shrink-0 items-center gap-xxs">
                                <button type="button" class="fx-btn fx-btn--icon h-9 w-9" wire:click="edit({{ $category->id }})" title="Editar categoria" aria-label="Editar {{ $category->name }}">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/>
                                    </svg>
                                </button>
                                <button type="button" class="fx-btn fx-btn--icon h-9 w-9 text-error" wire:click="delete({{ $category->id }})" wire:confirm="Excluir esta categoria?" title="Excluir categoria" aria-label="Excluir {{ $category->name }}">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        @if ($category->children->isNotEmpty())
                            <div class="mt-xs ml-[1.35rem] flex flex-col gap-xxs border-l border-mono-100 pl-sm">
                                @foreach ($category->children as $child)
                                    <div class="flex items-center gap-sm rounded-md border border-mono-100 bg-mono-50 p-xs">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style="background-color: {{ $this->softColor($child->color) }}; color: {{ $child->color ?: '#ff6f00' }}">
                                            <span class="material-icons-outlined text-[18px]">{{ $this->materialIcon($child->icon, $child->name) }}</span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-baseline gap-x-xs gap-y-xxxs">
                                                <span class="truncate text-xs font-semibold text-mono-900">{{ $child->name }}</span>
                                                <span class="text-xxs text-mono-600">{{ $child->transactions_count }} transações</span>
                                            </div>
                                        </div>
                                        <button type="button" class="fx-btn fx-btn--icon h-8 w-8" wire:click="edit({{ $child->id }})" title="Editar categoria" aria-label="Editar {{ $child->name }}">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/>
                                            </svg>
                                        </button>
                                        <button type="button" class="fx-btn fx-btn--icon h-8 w-8 text-error" wire:click="delete({{ $child->id }})" wire:confirm="Excluir esta categoria?" title="Excluir categoria" aria-label="Excluir {{ $child->name }}">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/>
                                            </svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-md border border-dashed border-mono-200 bg-mono-white p-lg text-sm text-mono-600">
                        Nenhuma categoria de receita cadastrada.
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    @if ($showFormModal)
        <div class="fixed inset-0 z-modal overflow-y-auto px-3 py-6">
            <button type="button" class="fixed inset-0 h-full w-full bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>

            <div class="relative mx-auto w-full max-w-[512px] overflow-hidden rounded-2xl bg-mono-white shadow-elevated">
                <div class="flex h-[66px] items-center justify-between border-b border-mono-100 px-6">
                    <h3 class="text-lg font-bold text-mono-900">{{ $editingId ? 'Editar Categoria' : 'Nova Categoria' }}</h3>
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl text-mono-300 transition-colors hover:bg-mono-100 hover:text-mono-600" wire:click="cancel" aria-label="Fechar">
                        <span class="material-icons-outlined text-[22px]">close</span>
                    </button>
                </div>

                <form wire:submit="save">
                    <div class="space-y-4 px-6 py-5">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Nome</label>
                            <div class="flex h-12 items-center gap-3 rounded-pill border border-mono-200 bg-mono-white px-4 transition-all focus-within:border-primary-500 focus-within:shadow-[0_0_0_3px_rgba(255,111,0,.1)]">
                                <span class="material-icons-outlined text-[20px]" style="color: {{ $color }}">label</span>
                                <input
                                    type="text"
                                    wire:model="name"
                                    placeholder="Nome da categoria"
                                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-mono-900 placeholder:text-mono-300 focus:outline-none focus:ring-0"
                                    required
                                />
                            </div>
                            @error('name') <div class="mt-2 text-xs font-medium text-error">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Tipo</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    wire:click="$set('type', 'expense')"
                                    class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $type === 'expense' ? 'border-down-bg bg-down-bg text-down' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                >
                                    <span class="material-icons-outlined text-[18px]">south</span>
                                    Despesa
                                </button>
                                <button
                                    type="button"
                                    wire:click="$set('type', 'income')"
                                    class="flex h-11 items-center justify-center gap-2 rounded-pill border text-sm font-semibold transition-all {{ $type === 'income' ? 'border-up-bg bg-up-bg text-up' : 'border-mono-200 bg-mono-50 text-mono-600 hover:bg-mono-100' }}"
                                >
                                    <span class="material-icons-outlined text-[18px]">north</span>
                                    Receita
                                </button>
                            </div>
                            @error('type') <div class="mt-2 text-xs font-medium text-error">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Subcategoria de</label>
                            <div class="relative">
                                <select wire:model="parent_id" class="h-12 w-full appearance-none rounded-pill border border-mono-200 bg-mono-white px-4 pr-11 text-sm text-mono-900 transition-all focus:border-primary-500 focus:outline-none focus:ring-0">
                                    <option value="">Nenhuma (categoria principal)</option>
                                    @foreach ($parents as $parent)
                                        @if ($parent->id !== $editingId)
                                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <span class="material-icons-outlined pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-[20px] text-mono-600">expand_more</span>
                            </div>
                            @error('parent_id') <div class="mt-2 text-xs font-medium text-error">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Cor</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->colorOptions() as $option)
                                    <button
                                        type="button"
                                        wire:click="$set('color', '{{ $option }}')"
                                        class="h-8 w-8 rounded-full transition-transform active:scale-95 {{ $color === $option ? 'ring-2 ring-mono-900 ring-offset-2 ring-offset-mono-white' : 'ring-0' }}"
                                        style="background-color: {{ $option }}"
                                        aria-label="Selecionar cor {{ $option }}"
                                    ></button>
                                @endforeach
                            </div>
                            @error('color') <div class="mt-2 text-xs font-medium text-error">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-mono-600">Ícone</label>
                            <div class="grid grid-cols-9 gap-2 max-[520px]:grid-cols-6">
                                @foreach ($this->iconOptions() as $option)
                                    <button
                                        type="button"
                                        wire:click="$set('icon', '{{ $option }}')"
                                        class="flex h-9 w-9 items-center justify-center rounded-xl transition-colors {{ $icon === $option ? 'bg-primary-100 text-primary-500' : 'bg-mono-50 text-mono-400 hover:bg-mono-100 hover:text-mono-600' }}"
                                        aria-label="Selecionar ícone {{ $option }}"
                                    >
                                        <span class="material-icons-outlined text-[20px]">{{ $option }}</span>
                                    </button>
                                @endforeach
                            </div>
                            @error('icon') <div class="mt-2 text-xs font-medium text-error">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-mono-100 bg-mono-50 px-6 py-4">
                        <button type="button" class="h-11 rounded-pill bg-mono-100 px-6 text-sm font-semibold text-mono-900 transition-colors hover:bg-mono-200" wire:click="cancel">Cancelar</button>
                        <button type="submit" class="h-11 rounded-pill bg-primary-500 px-6 text-sm font-semibold text-white transition-colors hover:bg-primary-600">
                            {{ $editingId ? 'Salvar Categoria' : 'Criar Categoria' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
