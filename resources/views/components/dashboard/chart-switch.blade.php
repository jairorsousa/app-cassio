@props(['widget', 'current', 'allowed' => []])
@if (count($allowed) > 1)
    <div class="inline-flex rounded-sm border border-cryptex-border-default overflow-hidden text-fs-12" role="group" aria-label="Tipo de gráfico">
        @foreach ($allowed as $type)
            <button type="button" wire:click="setChartType('{{ $widget }}', '{{ $type }}')" aria-pressed="{{ $current === $type ? 'true' : 'false' }}"
                class="px-2 py-1 min-h-[32px] {{ $current === $type ? 'bg-cryptex-brand-400 text-white' : 'text-cryptex-text-secondary hover:text-cryptex-text-primary' }}">
                {{ \App\Domains\Dashboard\Support\DashboardPreferences::CHART_LABELS[$type] }}
            </button>
        @endforeach
    </div>
@endif
