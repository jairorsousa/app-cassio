@props(['value' => 0])

<div {{ $attributes->merge(['class' => 'fx-progress']) }}>
    <div class="fx-progress-bar" style="width: {{ max(0, min(100, $value)) }}%"></div>
</div>
