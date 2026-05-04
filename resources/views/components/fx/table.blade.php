@props(['headers' => []])

<div class="w-full overflow-x-auto rounded-lg border border-cryptex-border-subtle bg-cryptex-bg-secondary">
    <table {{ $attributes->merge(['class' => 'w-full text-left border-collapse']) }}>
        @if (count($headers))
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th class="bg-cryptex-bg-tertiary px-space-4 py-space-3 text-fs-12 font-mono font-medium text-cryptex-text-tertiary uppercase tracking-wider border-b border-cryptex-border-subtle whitespace-nowrap">
                            {{ $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-cryptex-border-subtle">
            {{ $slot }}
        </tbody>
    </table>
</div>
