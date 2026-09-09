@props(['rows'])
@php $maximum = max(1, collect($rows)->max(fn ($row) => max($row['buy'], $row['sell'], $row['income']))); @endphp
<div class="mb-6 flex flex-wrap gap-4 text-xs text-mono-600">
    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-primary-500"></span>Compras / aplicações</span>
    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-info"></span>Vendas / resgates</span>
    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-up"></span>Proventos recebidos</span>
</div>
@if (collect($rows)->sum('buy') + collect($rows)->sum('sell') + collect($rows)->sum('income') == 0)
    <div class="flex h-52 items-center justify-center rounded-2xl bg-mono-50 text-sm text-mono-600">O gráfico será preenchido com suas movimentações.</div>
@else
    <div class="overflow-x-auto">
        <div class="flex min-w-0 gap-1 border-b border-mono-200 pb-2 sm:gap-3">
            @foreach ($rows as $row)
                <div class="min-w-0 flex-1">
                    <div class="flex h-48 items-end justify-center gap-1 border-b border-mono-100">
                        @foreach (['buy' => ['bg-primary-500', 'Compras'], 'sell' => ['bg-info', 'Vendas'], 'income' => ['bg-up', 'Proventos']] as $key => [$color, $label])
                            <div tabindex="0" class="w-1.5 rounded-t sm:w-3 {{ $color }}" style="height: {{ $row[$key] > 0 ? max(1, $row[$key] / $maximum * 100) : 0 }}%" title="{{ $row['label'] }} · {{ $label }}: R$ {{ number_format($row[$key], 2, ',', '.') }}"><span class="sr-only">{{ $label }}: {{ $row[$key] }}</span></div>
                        @endforeach
                    </div>
                    <p class="mt-3 text-center text-[9px] text-mono-600 sm:text-[10px]"><span class="sm:hidden">{{ explode('/', $row['label'])[0] }}</span><span class="hidden sm:inline">{{ $row['label'] }}</span></p>
                </div>
            @endforeach
        </div>
    </div>
    <details class="mt-4 text-xs text-mono-600"><summary class="cursor-pointer">Ver valores mensais</summary><div class="overflow-x-auto"><table class="investment-table"><thead><tr><th>Mês</th><th>Compras</th><th>Vendas</th><th>Proventos</th></tr></thead><tbody>@foreach ($rows as $row)<tr><td>{{ $row['label'] }}</td>@foreach (['buy', 'sell', 'income'] as $key)<td>R$ {{ number_format($row[$key], 2, ',', '.') }}</td>@endforeach</tr>@endforeach</tbody></table></div></details>
@endif
