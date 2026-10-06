@props(['model', 'id'])

<fieldset class="flex flex-wrap items-center gap-3">
    <legend class="mb-2 text-sm font-semibold text-mono-900">Ratear por</legend>
    @foreach (['amount' => 'Valor (R$)', 'percentage' => 'Percentual (%)'] as $value => $label)
        <label for="{{ $id }}-{{ $value }}" class="cursor-pointer">
            <input id="{{ $id }}-{{ $value }}" type="radio" name="{{ $id }}" value="{{ $value }}" wire:model.live="{{ $model }}" class="peer sr-only">
            <span class="inline-flex h-10 items-center rounded-pill border border-mono-200 bg-white px-4 text-sm font-medium text-mono-600 transition-colors peer-checked:border-primary-500 peer-checked:bg-primary-50 peer-checked:text-primary-600 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500">{{ $label }}</span>
        </label>
    @endforeach
</fieldset>
