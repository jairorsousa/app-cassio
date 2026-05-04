@props(['value' => 0])

<div {{ $attributes->merge(['class' => 'w-full h-2 rounded-full bg-cryptex-bg-tertiary overflow-hidden']) }}>
    <div class="h-full bg-cryptex-brand-400 transition-all duration-[400ms]" style="width: {{ max(0, min(100, $value)) }}%"></div>
</div>
