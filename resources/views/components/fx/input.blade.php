@props([
    'type' => 'text',
    'name' => null,
    'id' => null,
    'state' => null,
    'label' => null,
    'leadingIcon' => null,
    'trailingIcon' => null,
])

@php
    $wrapperClasses = 'fx-form-field';
    if ($state === 'success') $wrapperClasses .= ' fx-form-field--success';
    if ($state === 'error')   $wrapperClasses .= ' fx-form-field--error';
    if ($state === 'disabled')$wrapperClasses .= ' fx-form-field--disabled';
    $inputId = $id ?? $name;
@endphp

<div class="flex flex-col gap-xxxs">
    @if ($label)
        <label @if ($inputId) for="{{ $inputId }}" @endif class="block text-xxs text-mono-600">{{ $label }}</label>
    @endif
    <div class="{{ $wrapperClasses }}">
        @if ($leadingIcon)
            <span class="fx-field-icon">{!! $leadingIcon !!}</span>
        @endif
        <input
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" @endif
            @if ($inputId) id="{{ $inputId }}" @endif
            {{ $attributes }}
        />
        @if ($trailingIcon)
            <span class="fx-field-icon">{!! $trailingIcon !!}</span>
        @endif
    </div>
</div>
