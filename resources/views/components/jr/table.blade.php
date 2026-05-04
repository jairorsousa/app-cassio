@props([
    'striped' => false,
])

<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-2xl border border-mono-100 bg-mono-white']) }}>
    <table class="w-full min-w-[600px] border-collapse">
        @isset($head)
            <thead>
                <tr>{{ $head }}</tr>
            </thead>
        @endisset
        <tbody @class(['divide-y divide-mono-100', '[&>tr:nth-child(even)]:bg-mono-50' => $striped])>
            {{ $slot }}
        </tbody>
    </table>
</div>
