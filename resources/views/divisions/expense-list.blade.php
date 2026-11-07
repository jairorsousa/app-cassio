<x-fx.card data-expenses class="pb-20">
    <h3 class="mb-3 text-fs-16 font-semibold">Despesas</h3>
    @php($meId = $me?->id)
    @forelse ($expenses->groupBy(fn ($e) => $e->date->format('Y-m')) as $ym => $monthItems)
        <h4 class="mt-4 mb-1 border-b border-mono-100 pb-1 text-xs font-semibold uppercase tracking-wide text-mono-600 first:mt-0" data-month="{{ $ym }}">{{ ucfirst(\Illuminate\Support\Carbon::parse($ym.'-01')->locale('pt_BR')->translatedFormat('F \d\e Y')) }}</h4>
        @foreach ($monthItems as $e)
            @php($cents = (int) round((float) $e->amount * 100))
            @php($myShare = (int) round((float) ($meId ? ($e->shares->firstWhere('member_id', $meId)?->amount ?? 0) : 0) * 100))
            @php($iPaid = $meId && (int) $e->paid_by_member_id === (int) $meId)
            @php($involved = $meId && ($iPaid || $e->shares->contains('member_id', $meId)))
            @php($lent = $iPaid ? $cents - $myShare : -$myShare)
            <div class="border-b border-mono-100 py-3 last:border-0" x-data="{ open: false }" wire:key="e-{{ $e->id }}" data-expense-row data-lent="{{ $involved ? $lent : 'none' }}">
                <div class="flex items-start gap-3">
                    <button type="button" class="flex min-w-0 flex-1 items-start gap-3 text-left" x-on:click="open = !open">
                        <span class="w-10 shrink-0 text-center leading-tight text-mono-600"><span class="block text-[11px] uppercase">{{ \Illuminate\Support\Carbon::parse($e->date)->locale('pt_BR')->translatedFormat('M') }}</span><span class="block text-fs-18 font-semibold">{{ $e->date->format('d') }}</span></span>
                        <span class="min-w-0">
                            <span class="block truncate font-semibold text-mono-900">{{ $e->description }}
                                @if ($e->status !== 'approved')<span class="ml-1 rounded-pill {{ $e->status === 'pending' ? 'bg-primary-100 text-primary-500' : 'bg-mono-100 text-mono-600' }} px-2 text-xs">{{ \App\Domains\Divisions\Models\DivisionExpense::STATUS[$e->status] }}</span>@endif
                                @if ($isAdmin && $e->transaction_id)<span class="ml-1 rounded-pill bg-mono-100 px-2 text-xs" title="Vinculada ao Financeiro">Financeiro #{{ $e->transaction_id }}</span>@endif</span>
                            <span class="block text-xs text-mono-600" data-payer-line>{{ $iPaid ? 'Você' : $e->payer?->name }} pagou R$ {{ $service->money($cents) }} · {{ $e->categoryLabel() }}</span>
                        </span>
                    </button>
                    <div class="shrink-0 text-right text-xs leading-tight" data-my-part>
                        @if (! $involved)
                            <span class="text-mono-400" data-sign="none">não envolvido</span>
                        @elseif ($lent > 0)
                            <span class="block text-green-600" data-sign="pos">você emprestou</span><span class="block text-sm font-semibold text-green-600">R$ {{ $service->money($lent) }}</span>
                        @elseif ($lent < 0)
                            <span class="block text-red-600" data-sign="neg">você pegou emprestado</span><span class="block text-sm font-semibold text-red-600">R$ {{ $service->money(abs($lent)) }}</span>
                        @else
                            <span class="text-mono-400" data-sign="zero">sem saldo</span>
                        @endif
                    </div>
                </div>
                <div class="mt-1 flex flex-wrap justify-end gap-3 pl-[52px]">
                    @if ($isAdmin && $e->status === 'pending')
                        <button type="button" class="text-xs text-error" wire:click="decideExpense({{ $e->id }}, false)">Recusar</button>
                        <button type="button" class="text-xs text-green-600" wire:click="decideExpense({{ $e->id }}, true)">Aprovar</button>
                    @endif
                    @if ($isAdmin || ($e->created_by === auth()->id() && $e->status === 'pending'))
                        <button type="button" class="text-xs text-primary-500" wire:click="editExpense({{ $e->id }})">Editar</button>
                        <button type="button" class="text-xs text-error" wire:click="deleteExpense({{ $e->id }})" wire:confirm="Excluir esta despesa?">Excluir</button>
                    @endif
                </div>
                <div x-show="open" x-cloak class="mt-2 grid grid-cols-1 gap-3 rounded-xl bg-mono-50 p-3 text-xs md:grid-cols-2">
                    <div><p class="mb-1 font-semibold">Divisão</p>
                        @foreach ($e->shares as $s)<div class="flex justify-between"><span>{{ $s->member?->name }}</span><span>R$ {{ number_format((float) $s->amount, 2, ',', '.') }}</span></div>@endforeach
                        @if ($e->receipt_path)<a class="mt-2 inline-block text-primary-500" href="{{ route('divisions.receipt', $e) }}">Ver comprovante</a>@endif
                        @if ($e->notes)<p class="mt-2 text-mono-600">{{ $e->notes }}</p>@endif</div>
                    <div data-history><p class="mb-1 font-semibold">Histórico</p>
                        @foreach ($e->audits as $a)@continue(! $isAdmin && $a->action === 'expense_linked')<div>{{ $a->created_at?->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · {{ $a->label() }} · {{ $a->user?->name ?? 'sistema' }}</div>@endforeach</div>
                </div>
            </div>
        @endforeach
    @empty
        <p class="text-sm text-mono-600">Nenhuma despesa lançada.</p>
    @endforelse
</x-fx.card>
