@props(['name' => null, 'checked' => false])

<input
    type="checkbox"
    @if ($name) name="{{ $name }}" @endif
    @checked($checked)
    {{ $attributes->merge(['class' => 'fx-toggle']) }}
/>
