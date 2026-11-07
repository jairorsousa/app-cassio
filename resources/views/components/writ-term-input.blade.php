@props(['model' => 'estimated_months', 'label' => 'Prazo estimado'])
{{-- Prazo em meses ou anos; grava sempre em meses no campo Livewire indicado. --}}
<div x-data="{
        months: $wire.entangle('{{ $model }}').live,
        unit: 'meses',
        qty: '',
        init() {
            this.fromMonths(this.months);
            this.$watch('months', v => { if (this.toMonths() !== (v === null || v === '' ? null : Number(v))) this.fromMonths(v); });
        },
        fromMonths(v) {
            const m = (v === null || v === '' || isNaN(Number(v))) ? null : Number(v);
            if (m === null) { this.qty = ''; return; }
            if (m >= 12 && m % 12 === 0) { this.unit = 'anos'; this.qty = String(m / 12); } else { this.unit = 'meses'; this.qty = String(m); }
        },
        toMonths() {
            const q = parseInt(this.qty, 10);
            if (isNaN(q) || q < 0) return null;
            return this.unit === 'anos' ? q * 12 : q;
        },
        sync() { this.months = this.toMonths(); }
    }" data-writ-term>
    <label class="mb-2 block text-sm font-medium text-mono-600">{{ $label }}</label>
    <div class="flex gap-2">
        <input type="number" min="0" step="1" x-model="qty" x-on:input.debounce.300ms="sync()" class="h-12 w-24 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm" aria-label="Quantidade">
        <select x-model="unit" x-on:change="sync()" class="h-12 flex-1 rounded-pill border border-mono-200 bg-mono-white px-4 text-sm" aria-label="Unidade" data-writ-term-unit>
            <option value="meses">meses</option>
            <option value="anos">anos</option>
        </select>
    </div>
    <p class="mt-1 text-xs text-mono-600" x-text="months ? '= ' + months + ' meses' : 'Padrão: precatório 24 meses, RPV 9 meses'"></p>
</div>
