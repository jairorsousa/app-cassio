@props(['headers' => []])

<table {{ $attributes->merge(['class' => 'fx-table']) }}>
    @if (count($headers))
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
    @endif
    <tbody>
        {{ $slot }}
    </tbody>
</table>
