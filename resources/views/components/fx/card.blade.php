@props(['title' => null])

<div {{ $attributes->merge(['class' => 'bg-cryptex-bg-secondary border border-cryptex-border-subtle p-space-5 rounded-lg hover:border-cryptex-border-default transition-colors duration-250']) }}>
    @if ($title)
        <div class="text-fs-18 font-bold text-cryptex-text-primary mb-space-4">{{ $title }}</div>
    @endif
    {{ $slot }}
</div>
