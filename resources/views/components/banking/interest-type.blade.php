@props([
    'model',
    'id',
    'preview' => null,
    'legend' => 'Tipo de juros',
])

<fieldset class="min-w-0" data-interest-type="{{ $id }}">
    <legend class="mb-2 text-sm text-mono-600">{{ $legend }}</legend>
    <div class="flex gap-2">
        @foreach (['simple' => 'Simples', 'compound' => 'Compostos'] as $value => $label)
            <label for="{{ $id }}-{{ $value }}" class="min-w-0 flex-1 cursor-pointer">
                <input id="{{ $id }}-{{ $value }}" type="radio" name="{{ $id }}" value="{{ $value }}" wire:model.live="{{ $model }}" class="peer sr-only">
                <span class="inline-flex h-11 w-full items-center justify-center rounded-pill border border-mono-200 bg-white px-3 text-sm font-semibold text-mono-600 transition-colors peer-checked:border-primary-500 peer-checked:bg-primary-50 peer-checked:text-primary-600 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500">{{ $label }}</span>
            </label>
        @endforeach
    </div>
    @if ($preview)
        <p class="mt-2 text-xs text-mono-600" data-interest-preview="{{ $id }}">
            Prévia {{ $preview['until_label'] }}: Simples: {{ $preview['simple'] }} · Compostos: {{ $preview['compound'] }}
        </p>
    @endif
</fieldset>
