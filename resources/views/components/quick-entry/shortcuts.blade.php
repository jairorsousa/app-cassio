{{-- Atalhos grandes do topo (painel e visão geral do Financeiro): abrem o lançamento rápido já com o tipo escolhido. --}}
@if (\App\Support\QuickEntry::enabled(auth()->user()))
    <div {{ $attributes->merge(['class' => 'grid grid-cols-3 gap-3']) }} data-quick-shortcuts>
        <button type="button" class="qe-shortcut qe-shortcut-expense" x-on:click="Livewire.dispatch('quick-entry', { type: 'expense' })" data-quick-shortcut="expense">
            <span class="material-icons-outlined" aria-hidden="true">trending_down</span><span>Despesa</span>
        </button>
        <button type="button" class="qe-shortcut qe-shortcut-income" x-on:click="Livewire.dispatch('quick-entry', { type: 'income' })" data-quick-shortcut="income">
            <span class="material-icons-outlined" aria-hidden="true">trending_up</span><span>Receita</span>
        </button>
        <button type="button" class="qe-shortcut qe-shortcut-transfer" x-on:click="Livewire.dispatch('quick-entry', { type: 'transfer' })" data-quick-shortcut="transfer">
            <span class="material-icons-outlined" aria-hidden="true">sync_alt</span><span>Transferência</span>
        </button>
    </div>
@endif
