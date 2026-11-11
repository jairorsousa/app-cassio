{{-- Forma de pagamento: painel, entradas e financiamento. $p carrega downPayments e financings.installments. --}}
@php
    $brl = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 2, ',', '.');
    $s = $p->financingSummary();
    $in = 'h-11 rounded-sm border border-cryptex-border-default bg-cryptex-bg-tertiary px-2 w-full';
@endphp
<x-fx.card data-financing="{{ $p->id }}">
    <div x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })" class="flex items-start gap-space-3">
        <div class="min-w-0">
            <div class="text-fs-12 uppercase text-cryptex-text-tertiary">Forma de pagamento</div>
            <h3 class="text-fs-16 font-semibold break-words">{{ $p->description }}</h3>
        </div>
        <button type="button" wire:click="closePayments" class="ml-auto min-h-[40px] px-2 text-cryptex-text-secondary" aria-label="Fechar">✕</button>
    </div>

    <div class="mt-space-3 grid grid-cols-2 gap-2 rounded-sm border border-cryptex-border-default p-3 text-sm" data-financing-panel>
        <div class="text-cryptex-text-secondary">Preço do bem</div><strong class="font-mono text-right">{{ $brl($s['price']) }}</strong>
        <div class="text-cryptex-text-secondary">Total já pago</div><strong class="font-mono text-right">{{ $brl($s['paid']) }}</strong>
        <div class="text-cryptex-text-secondary">Saldo devedor</div><strong class="font-mono text-right">{{ $brl($s['remaining_debt']) }}</strong>
        <div class="text-cryptex-text-secondary">Parcelas que faltam</div><strong class="text-right">{{ $s['remaining_count'] }}</strong>
        <div class="text-cryptex-text-secondary">Total final</div><strong class="font-mono text-right">{{ $brl($s['total_final']) }}</strong>
        <div class="text-cryptex-text-secondary">Custo dos juros</div><strong class="font-mono text-right">{{ $brl($s['interest_cost']) }}</strong>
        @if ($s['debt_includes_future_interest'])
            <p class="col-span-2 text-xs text-cryptex-text-tertiary">Sem taxa informada, o saldo devedor é a soma das parcelas que faltam (inclui juros futuros).</p>
        @endif
        <p class="col-span-2 text-xs text-cryptex-text-tertiary">As parcelas inteiras saem da conta, mas ficam fora do resultado do mês. Os juros aparecem só aqui (total final menos o preço).</p>
    </div>

    {{-- Entradas --}}
    <div class="mt-space-4" data-down-payments>
        <h4 class="font-semibold text-sm">Entrada</h4>
        <div class="mt-2 divide-y divide-cryptex-border-default">
            @forelse ($p->downPayments as $down)
                <div class="py-3 text-sm" data-down-payment="{{ $down->id }}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold">{{ $down->isPaid() ? 'Paga' : 'Prevista' }}@if ($down->date) · {{ $down->date->format('d/m/Y') }}@endif</div>
                            <div class="text-xs text-cryptex-text-secondary">{{ $down->bankAccount?->name ?? 'Conta a informar' }}@if ($down->notes) · {{ $down->notes }}@endif</div>
                            @if ($down->transaction_id)
                                <div class="text-xs text-cryptex-text-tertiary">{{ $down->linked_existing ? 'Vinculada ao extrato' : 'Lançamento na conta' }}</div>
                            @elseif ($down->isPaid() && $down->date && $down->bank_account_id)
                                <div class="text-xs text-cryptex-text-tertiary">Histórico (não mexeu no saldo da conta)</div>
                            @endif
                        </div>
                        <strong class="font-mono whitespace-nowrap">{{ $brl($down->amount) }}</strong>
                    </div>
                    <div class="mt-2 flex justify-end">
                        <button type="button" wire:click="deleteDownPayment({{ $down->id }})" wire:confirm="Apagar esta entrada?" class="min-h-[36px] text-xs text-cryptex-red-500">Apagar</button>
                    </div>
                </div>
            @empty
                <p class="py-2 text-sm text-cryptex-text-tertiary">Nenhuma entrada registrada.</p>
            @endforelse
        </div>
        @error('downForm')<x-fx.alert variant="error" class="mt-2">{{ $message }}</x-fx.alert>@enderror
        @if (! $showDownForm)
            <button type="button" wire:click="newDownPayment" class="mt-2 min-h-[44px] w-full rounded-sm border border-dashed border-cryptex-brand-400 font-semibold text-cryptex-brand-400" data-new-down>+ Entrada</button>
        @else
            <form wire:submit="saveDownPayment" class="mt-2 grid gap-space-3 rounded-sm border border-cryptex-border-default p-3 text-sm" data-down-form>
                <h4 class="font-semibold">Nova entrada</h4>
                <label class="flex flex-col gap-1">Valor<input type="text" inputmode="numeric" x-money wire:model="downAmount" class="{{ $in }}" placeholder="R$ 0,00" /></label>
                <label class="flex flex-col gap-1">Data<input type="date" wire:model="downDate" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Conta (opcional até você preencher)
                    <select wire:model="downAccount" class="{{ $in }}"><option value="">A informar</option>@foreach ($payAccounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                </label>
                <label class="flex flex-col gap-1">Observação (opcional)<input type="text" wire:model="downNotes" maxlength="500" class="{{ $in }}" /></label>
                @if ($downWarnings)
                    <div class="rounded-sm border border-[rgba(240,185,11,0.4)] bg-[rgba(240,185,11,0.1)] p-3 text-cryptex-brand-400" data-down-warnings>
                        @foreach ($downWarnings as $w)<p class="mb-2">{{ $w }}</p>@endforeach
                        @if ($downDuplicates)
                            <label class="flex flex-col gap-1 text-cryptex-text-primary">Vincular a um lançamento já existente
                                <select wire:model="downLinkId" class="{{ $in }} bg-white text-black">
                                    <option value="">Criar um lançamento novo</option>
                                    @foreach ($downDuplicates as $d)
                                        <option value="{{ $d['id'] }}">{{ $d['date'] }} · R$ {{ number_format($d['amount'], 2, ',', '.') }} · {{ $d['description'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        <button type="button" wire:click="saveDownPayment(true)" class="mt-2 min-h-[40px] rounded-sm border border-cryptex-brand-400 px-3 font-semibold">Confirmar</button>
                    </div>
                @endif
                <div class="flex gap-space-3">
                    <button type="submit" class="min-h-[44px] flex-1 rounded-sm bg-cryptex-brand-400 px-3 font-semibold text-white">Salvar entrada</button>
                    <button type="button" wire:click="$set('showDownForm', false)" class="min-h-[44px] rounded-sm border border-cryptex-border-default px-3">Cancelar</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Financiamento --}}
    <div class="mt-space-4" data-financings>
        <h4 class="font-semibold text-sm">Financiamento</h4>
        @forelse ($p->financings as $fin)
            <div class="mt-2 rounded-sm border border-cryptex-border-default p-3 text-sm" data-financing-contract="{{ $fin->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold">{{ $fin->creditor }}</div>
                        <div class="text-xs text-cryptex-text-secondary">{{ $brl($fin->financed_amount) }} em {{ $fin->installment_count }}x de {{ $brl($fin->installment_amount) }} · 1ª em {{ $fin->first_due_date->format('d/m/Y') }}</div>
                        @if ($fin->hasRate())
                            <div class="text-xs text-cryptex-text-tertiary">{{ number_format((float) $fin->interest_rate_am, 4, ',', '.') }}% a.m. · {{ \App\Domains\Assets\Models\PropertyFinancing::SYSTEMS[$fin->amortization_system] ?? $fin->amortization_system }}</div>
                        @endif
                    </div>
                    @if ($fin->installments->where('status', 'paid')->isEmpty())
                        <button type="button" wire:click="deleteFinancing({{ $fin->id }})" wire:confirm="Apagar este financiamento e todas as parcelas em aberto?" class="min-h-[36px] text-xs text-cryptex-red-500">Apagar</button>
                    @endif
                </div>

                @error('instForm')<x-fx.alert variant="error" class="mt-2">{{ $message }}</x-fx.alert>@enderror

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-xs" data-installments="{{ $fin->id }}">
                        <thead><tr class="text-left text-cryptex-text-tertiary"><th class="pr-1 w-8"></th><th class="pr-2">Nº</th><th class="pr-2">Vencimento</th><th class="pr-2 text-right">Valor</th><th class="pr-2">Situação</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($fin->installments as $inst)
                                <tr class="border-t border-cryptex-border-subtle" data-installment="{{ $inst->id }}" data-status="{{ $inst->status }}">
                                    <td class="py-2">
                                        @if ($inst->isOpen())
                                            <input type="checkbox" wire:model="selectedInstallments" value="{{ $inst->id }}" class="h-4 w-4" aria-label="Selecionar parcela {{ $inst->number }}" />
                                        @endif
                                    </td>
                                    <td>{{ $inst->number }}</td>
                                    <td>{{ $inst->due_date->format('d/m/Y') }}</td>
                                    <td class="text-right font-mono">{{ $brl($inst->amount) }}</td>
                                    <td>
                                        @if ($inst->isPaid())
                                            <span class="text-system-up">Paga{{ $inst->paid_at ? ' em '.$inst->paid_at->format('d/m/Y') : '' }}</span>
                                            <div class="text-[11px] text-cryptex-text-tertiary">
                                                {{ $inst->bankAccount?->name ?? 'Conta não informada' }}
                                                @if ($inst->transaction_id)
                                                    · {{ $inst->linked_existing ? 'vinculada ao extrato' : 'lançamento na conta' }}
                                                @elseif ($inst->paid_at && $inst->bank_account_id)
                                                    · histórico, sem mexer no saldo
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-cryptex-text-secondary">Em aberto</span>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        @if ($inst->isOpen())
                                            <button type="button" wire:click="markInstallmentForm({{ $inst->id }})" class="min-h-[36px] px-2 font-semibold text-cryptex-brand-400">Paguei</button>
                                        @else
                                            <button type="button" wire:click="unpayInstallment({{ $inst->id }})" wire:confirm="Desfazer o pagamento desta parcela?" class="min-h-[36px] px-2 text-cryptex-red-500">Desfazer</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="openBatchForm" class="min-h-[44px] rounded-sm border border-cryptex-brand-400 px-3 font-semibold text-cryptex-brand-400" data-batch-paid>Marcar selecionadas como pagas</button>
                </div>

                @if ($showInstPayForm && $payingInstallmentId)
                    <form wire:submit="saveInstallmentPaid" class="mt-3 grid gap-space-3 rounded-sm border border-cryptex-border-default p-3" data-inst-form>
                        <h4 class="font-semibold">Marcar parcela como paga</h4>
                        <label class="flex flex-col gap-1">Data do pagamento<input type="date" wire:model="instPaidAt" class="{{ $in }}" /></label>
                        <label class="flex flex-col gap-1">Conta de onde saiu (pode deixar em branco e preencher depois)
                            <select wire:model="instAccount" class="{{ $in }}"><option value="">A informar</option>@foreach ($payAccounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                        </label>
                        @if ($instWarnings)
                            <div class="rounded-sm border border-[rgba(240,185,11,0.4)] bg-[rgba(240,185,11,0.1)] p-3 text-cryptex-brand-400" data-inst-warnings>
                                @foreach ($instWarnings as $w)<p class="mb-2">{{ $w }}</p>@endforeach
                                @if ($instDuplicates)
                                    <label class="flex flex-col gap-1 text-cryptex-text-primary">Vincular a um lançamento já existente
                                        <select wire:model="instLinkId" class="{{ $in }} bg-white text-black">
                                            <option value="">Criar um lançamento novo</option>
                                            @foreach ($instDuplicates as $d)
                                                <option value="{{ $d['id'] }}">{{ $d['date'] }} · R$ {{ number_format($d['amount'], 2, ',', '.') }} · {{ $d['description'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endif
                                <button type="button" wire:click="saveInstallmentPaid(true)" class="mt-2 min-h-[40px] rounded-sm border border-cryptex-brand-400 px-3 font-semibold">Confirmar</button>
                            </div>
                        @endif
                        <div class="flex gap-space-3">
                            <button type="submit" class="min-h-[44px] flex-1 rounded-sm bg-cryptex-brand-400 px-3 font-semibold text-white">Confirmar pagamento</button>
                            <button type="button" wire:click="$set('showInstPayForm', false)" class="min-h-[44px] rounded-sm border border-cryptex-border-default px-3">Cancelar</button>
                        </div>
                    </form>
                @endif

                @if ($showBatchForm)
                    <form wire:submit="saveBatch" class="mt-3 grid gap-space-3 rounded-sm border border-cryptex-border-default p-3" data-batch-form>
                        <h4 class="font-semibold">Pagar parcelas em lote</h4>
                        <p class="text-xs text-cryptex-text-secondary">Cada parcela pode ter data e conta próprias (o boleto pode sair de contas diferentes).</p>
                        <div class="grid gap-2 md:grid-cols-3">
                            <label class="flex flex-col gap-1">Preencher todas com a data<input type="date" wire:model="batchFillDate" class="{{ $in }}" /></label>
                            <label class="flex flex-col gap-1">e a conta
                                <select wire:model="batchFillAccount" class="{{ $in }}"><option value="">A informar</option>@foreach ($payAccounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                            </label>
                            <button type="button" wire:click="applyBatchFill" class="min-h-[44px] self-end rounded-sm border border-cryptex-border-default px-3">Aplicar nas selecionadas</button>
                        </div>
                        @foreach ($batchRows as $id => $row)
                            <div class="grid gap-2 rounded-sm border border-cryptex-border-subtle p-2 md:grid-cols-3" data-batch-row="{{ $id }}">
                                <div class="text-xs self-center">Parcela {{ $row['number'] }} · venc. {{ $row['due'] }} · {{ $brl($row['amount']) }}</div>
                                <label class="flex flex-col gap-1 text-xs">Data do pagamento<input type="date" wire:model="batchRows.{{ $id }}.paid_at" class="{{ $in }}" /></label>
                                <label class="flex flex-col gap-1 text-xs">Conta
                                    <select wire:model="batchRows.{{ $id }}.account" class="{{ $in }}"><option value="">A informar</option>@foreach ($payAccounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                                </label>
                                @if (!empty($batchWarnings[$id]))
                                    <div class="md:col-span-3 text-xs text-cryptex-brand-400">
                                        @foreach ($batchWarnings[$id] as $w)<p>{{ $w }}</p>@endforeach
                                        @if (!empty($batchDuplicates[$id]))
                                            <label class="mt-1 flex flex-col gap-1 text-cryptex-text-primary">Vincular
                                                <select wire:model="batchRows.{{ $id }}.link" class="{{ $in }}">
                                                    <option value="">Criar um lançamento novo</option>
                                                    @foreach ($batchDuplicates[$id] as $d)
                                                        <option value="{{ $d['id'] }}">{{ $d['date'] }} · {{ $d['description'] }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        <div class="flex gap-space-3">
                            <button type="submit" class="min-h-[44px] flex-1 rounded-sm bg-cryptex-brand-400 px-3 font-semibold text-white">{{ $batchWarnings ? 'Confirmar mesmo assim' : 'Marcar como pagas' }}</button>
                            <button type="button" wire:click="$set('showBatchForm', false)" class="min-h-[44px] rounded-sm border border-cryptex-border-default px-3">Cancelar</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <p class="mt-2 text-sm text-cryptex-text-tertiary">Nenhum financiamento cadastrado.</p>
        @endforelse

        @error('finForm')<x-fx.alert variant="error" class="mt-2">{{ $message }}</x-fx.alert>@enderror
        @if (! $showFinancingForm && $p->financings->isEmpty())
            <button type="button" wire:click="newFinancing" class="mt-2 min-h-[44px] w-full rounded-sm border border-dashed border-cryptex-brand-400 font-semibold text-cryptex-brand-400" data-new-financing>+ Financiamento</button>
        @elseif ($showFinancingForm)
            <form wire:submit="saveFinancing" class="mt-2 grid gap-space-3 rounded-sm border border-cryptex-border-default p-3 text-sm md:grid-cols-2" data-financing-form>
                <h4 class="font-semibold md:col-span-2">Novo financiamento</h4>
                <label class="flex flex-col gap-1 md:col-span-2">Banco ou credor<input type="text" wire:model="finCreditor" class="{{ $in }}" placeholder="Ex.: Bradesco" /></label>
                <label class="flex flex-col gap-1">Valor financiado<input type="text" inputmode="numeric" x-money wire:model="finAmount" class="{{ $in }}" placeholder="R$ 0,00" /></label>
                <label class="flex flex-col gap-1">Número de parcelas<input type="number" min="1" max="240" wire:model="finCount" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Valor da parcela<input type="text" inputmode="numeric" x-money wire:model="finInstallment" class="{{ $in }}" placeholder="R$ 0,00" /></label>
                <label class="flex flex-col gap-1">1º vencimento<input type="date" wire:model="finFirstDue" class="{{ $in }}" /></label>
                <label class="flex flex-col gap-1">Taxa % a.m. (opcional)<input type="text" inputmode="decimal" wire:model="finRate" class="{{ $in }}" placeholder="Ex.: 1,49" /></label>
                <label class="flex flex-col gap-1">Tabela (opcional)
                    <select wire:model="finSystem" class="{{ $in }}"><option value="">Não informar</option><option value="price">Tabela Price</option><option value="sac">SAC</option></select>
                </label>
                <label class="flex flex-col gap-1 md:col-span-2">Observação (opcional)<input type="text" wire:model="finNotes" maxlength="500" class="{{ $in }}" /></label>
                @if ($finWarnings)
                    <div class="rounded-sm border border-[rgba(240,185,11,0.4)] bg-[rgba(240,185,11,0.1)] p-3 text-cryptex-brand-400 md:col-span-2">
                        @foreach ($finWarnings as $w)<p class="mb-1">{{ $w }}</p>@endforeach
                        <button type="button" wire:click="saveFinancing(true)" class="mt-1 min-h-[40px] rounded-sm border border-cryptex-brand-400 px-3 font-semibold">Salvar mesmo assim</button>
                    </div>
                @endif
                <div class="flex gap-space-3 md:col-span-2">
                    <button type="submit" class="min-h-[44px] flex-1 rounded-sm bg-cryptex-brand-400 px-3 font-semibold text-white md:flex-none">Gerar parcelas</button>
                    <button type="button" wire:click="$set('showFinancingForm', false)" class="min-h-[44px] rounded-sm border border-cryptex-border-default px-3">Cancelar</button>
                </div>
            </form>
        @endif
    </div>
</x-fx.card>
