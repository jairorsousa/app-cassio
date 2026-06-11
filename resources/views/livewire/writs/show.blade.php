<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Services\WritProfitabilityCalculator;
use App\Domains\Writs\Services\WritService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public Writ $writ;

    public string $transitionTo = '';
    public string $transition_cession_at = '';
    public string $transition_paid_at = '';
    public string $transition_paid_amount = '';
    public string $transition_notary_expenses = '';
    public string $transition_other_expenses = '';
    public ?int $transition_source_account = null;
    public string $transition_petitioned_at = '';
    public string $transition_finalized_at = '';
    public string $transition_actual_receipt_amount = '';
    public ?int $transition_destination_account = null;
    public string $transition_notes = '';

    public function mount(Writ $writ): void
    {
        $this->writ = $writ->load('history.user', 'transactions', 'assignors.contact');
        $this->transition_cession_at = now()->format('Y-m-d\TH:i');
        $this->transition_paid_at = now()->format('Y-m-d');
        $this->transition_petitioned_at = now()->format('Y-m-d\TH:i');
        $this->transition_finalized_at = now()->format('Y-m-d');
        $this->transition_paid_amount = (string) $writ->paid_amount;
        $this->transition_notary_expenses = (string) $writ->notary_expenses_amount;
        $this->transition_other_expenses = (string) $writ->other_expenses_amount;
        $this->transition_source_account = $writ->source_bank_account_id;
        $this->transition_destination_account = $writ->destination_bank_account_id;
    }

    private function moneyValue(string|int|float|null $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $value = (string) $value;

        if (str_contains($value, ',')) {
            $digits = preg_replace('/\D/', '', $value);
            return (float) ($digits / 100);
        }

        return (float) $value;
    }

    public function transition(WritService $service)
    {
        $this->validate([
            'transitionTo' => 'required|in:'.implode(',', Writ::STAGES),
        ]);

        $context = ['notes' => $this->transition_notes ?: null];

        if ($this->transitionTo === 'pending') {
            $context['cession_at'] = $this->transition_cession_at ?: null;
        }

        if ($this->transitionTo === 'paid') {
            $this->writ->update([
                'notary_expenses_amount' => $this->moneyValue($this->transition_notary_expenses),
                'other_expenses_amount' => $this->moneyValue($this->transition_other_expenses),
            ]);

            $context['paid_at'] = $this->transition_paid_at;
            $context['paid_amount'] = $this->moneyValue($this->transition_paid_amount);
            $context['source_bank_account_id'] = $this->transition_source_account;
        }

        if ($this->transitionTo === 'petitioning') {
            $context['petitioned_at'] = $this->transition_petitioned_at ?: null;
        }

        if ($this->transitionTo === 'finalized') {
            $context['finalized_at'] = $this->transition_finalized_at;
            $context['actual_receipt_amount'] = $this->moneyValue($this->transition_actual_receipt_amount);
            $context['destination_bank_account_id'] = $this->transition_destination_account;
        }

        try {
            $service->transitionTo($this->writ, $this->transitionTo, $context);
            session()->flash('status', 'Transição efetivada.');
            return $this->redirectRoute('writs.show', $this->writ, navigate: true);
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function delete(): void
    {
        // Remove transações bancárias vinculadas (polymorphic)
        $this->writ->transactions()->delete();

        // Remove histórico de etapas
        $this->writ->history()->delete();

        // Remove logs de atividade (Spatie)
        \Spatie\Activitylog\Models\Activity::where('subject_type', Writ::class)
            ->where('subject_id', $this->writ->id)
            ->delete();

        // Exclui permanentemente o requisitório
        $this->writ->forceDelete();

        session()->flash('status', 'Requisitório excluído com sucesso.');
        $this->redirectRoute('writs.kanban', navigate: true);
    }

    public function with(WritProfitabilityCalculator $calc): array
    {
        return [
            'profitability' => $calc->realized($this->writ),
            'accounts' => BankAccount::active()->orderBy('name')->get(),
            'history' => $this->writ->history,
            'transactions' => $this->writ->transactions,
        ];
    }
}; ?>

<div class="flex flex-col gap-6 pt-4 pb-12">
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
    @if (session('error'))<x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>@endif

    <div class="flex items-center justify-between mb-2">
        <a href="{{ route('writs.kanban') }}" class="inline-flex items-center gap-2 text-sm font-medium text-mono-500 hover:text-mono-900 transition-colors">
            <span class="material-icons-outlined text-[18px]">arrow_back</span> Voltar
        </a>
        <a href="{{ route('writs.edit', $writ) }}" class="inline-flex items-center gap-2 rounded-lg border border-primary-500 px-4 py-2 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition-colors">
            <span class="material-icons-outlined text-[18px]">edit_note</span> Editar dados
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Esquerda: Detalhes -->
        <x-fx.card class="lg:col-span-2 flex flex-col gap-6">
            <div class="flex items-center gap-2">
                <span class="material-icons-outlined text-primary-500">assignment</span>
                <h2 class="text-lg font-bold text-mono-900">Detalhes da requisição</h2>
            </div>

            <!-- 4 columns grid for Tipo, Status, Vencimento, Natureza -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 border-b border-mono-100 pb-6">
                <div>
                    <div class="flex items-center gap-1 text-xs text-mono-500 mb-1">
                        <span class="material-icons-outlined text-[16px]">receipt_long</span> Tipo
                    </div>
                    <div class="font-medium text-sm">
                        <span class="inline-flex items-center rounded-full bg-primary-100 px-2.5 py-0.5 text-xs font-semibold text-primary-700">
                            {{ $writ->type === 'rpv' ? 'RPV' : 'Precatório' }}
                        </span>
                    </div>
                </div>
                <div>
                    <div class="flex items-center gap-1 text-xs text-mono-500 mb-1">
                        Status
                    </div>
                    <div class="font-medium text-sm">
                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-600 mr-1.5"></span>
                            {{ $writ->stageLabel() }}
                        </span>
                    </div>
                </div>
                <div>
                    <div class="flex items-center gap-1 text-xs text-mono-500 mb-1">
                        <span class="material-icons-outlined text-[16px]">calendar_today</span> Vencimento
                    </div>
                    <div class="font-medium text-sm text-mono-900">
                        {{ $writ->cession_at ? $writ->cession_at->format('d/m/Y') : '—' }}
                    </div>
                </div>
                <div>
                    <div class="flex items-center gap-1 text-xs text-mono-500 mb-1">
                        <span class="material-icons-outlined text-[16px]">folder_open</span> Natureza
                    </div>
                    <div class="font-medium text-sm text-mono-900">
                        {{ $writ->credit_nature ?: '—' }}
                    </div>
                </div>
            </div>

            <!-- Processo -->
            <div class="border-b border-mono-100 pb-6">
                <div class="text-xs text-mono-500 mb-1">Processo</div>
                <div class="flex items-center gap-2 font-medium text-sm text-mono-900 mb-4">
                    <span class="material-icons-outlined text-[18px] text-mono-400">balance</span>
                    {{ $writ->process_number ?: '—' }}
                </div>
                <div class="text-xs text-mono-500 mb-1">Vara/Tribunal</div>
                <div class="font-medium text-sm text-mono-900">
                    {{ $writ->court ?: '—' }}
                </div>
                <div class="text-xs text-mono-500 mb-1 mt-4">Ente devedor</div>
                <div class="font-medium text-sm text-mono-900">
                    {{ $writ->debtor_entity ?: '—' }}
                </div>
            </div>

            <!-- Cedentes -->
            <div class="border-b border-mono-100 pb-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-icons-outlined text-primary-500">people_outline</span>
                    <h3 class="text-base font-bold text-mono-900">Cedentes</h3>
                </div>
                <div class="text-xs text-mono-500 mb-2">Parte</div>
                @if ($writ->assignors->isEmpty())
                    <div class="text-sm text-mono-600">Nenhum cedente vinculado.</div>
                @else
                    <div class="flex flex-col gap-4">
                        @foreach ($writ->assignors as $a)
                            <div class="flex items-center gap-3 text-sm">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700">
                                    {{ str($a->contact->name)->substr(0, 2)->upper() }}
                                </div>
                                <div>
                                    <div class="font-bold text-mono-900 uppercase">{{ $a->contact->name }}</div>
                                    <div class="text-xs text-mono-500">
                                        CPF: {{ $a->contact->document ?: '—' }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Valores -->
            <div class="bg-mono-50 rounded-xl p-6 border border-mono-100">
                <div class="flex items-center gap-2 mb-6">
                    <span class="material-icons-outlined text-primary-500">monetization_on</span>
                    <h3 class="text-base font-bold text-mono-900">Valores</h3>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-y-6 gap-x-4">
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Face</div>
                        <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->face_value, 2, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Parte negociada</div>
                        <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->negotiated_amount, 2, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Valor da proposta</div>
                        <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->proposed_amount, 2, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Valor pago ao cedente</div>
                        <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->paid_amount, 2, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Despesas cartorárias</div>
                        <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->notary_expenses_amount, 2, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Outras despesas</div>
                        <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->other_expenses_amount, 2, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Custo total</div>
                        <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->totalCost(), 2, ',', '.') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-mono-500 mb-1">Deságio calculado</div>
                        <div class="font-bold text-sm text-mono-900">{{ number_format($writ->discount_percentage, 2, ',', '.') }}%</div>
                    </div>
                    @if ($writ->stage === 'finalized')
                        <div>
                            <div class="text-xs text-mono-500 mb-1">Valor recebido</div>
                            <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->actual_receipt_amount, 2, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-mono-500 mb-1">Data do recebimento</div>
                            <div class="font-bold text-sm text-mono-900">{{ $writ->finalized_at ? $writ->finalized_at->format('d/m/Y') : '—' }}</div>
                        </div>
                        <div class="col-span-2 lg:col-span-1 border-t border-mono-100 pt-3 mt-1 lg:border-t-0 lg:pt-0 lg:mt-0">
                            <div class="text-xs text-mono-500 mb-1">Lucro real (R$)</div>
                            <div class="font-bold text-sm text-up">R$ {{ number_format($writ->actualProfit(), 2, ',', '.') }}</div>
                        </div>
                        <div class="col-span-2 lg:col-span-1 border-t border-mono-100 pt-3 mt-1 lg:border-t-0 lg:pt-0 lg:mt-0">
                            <div class="text-xs text-mono-500 mb-1">Lucro real (%)</div>
                            <div class="font-bold text-sm text-up">{{ number_format($writ->actualProfitPercentage(), 2, ',', '.') }}%</div>
                        </div>
                        <div class="col-span-2 lg:col-span-1 border-t border-mono-100 pt-3 mt-1 lg:border-t-0 lg:pt-0 lg:mt-0">
                            <div class="text-xs text-mono-500 mb-1">Prazo real</div>
                            <div class="font-bold text-sm text-mono-900">{{ $writ->actualMonths() ?? '—' }} meses</div>
                        </div>
                        <div class="col-span-2 lg:col-span-1 border-t border-mono-100 pt-3 mt-1 lg:border-t-0 lg:pt-0 lg:mt-0">
                            <div class="text-xs text-mono-500 mb-1">Lucro real / Mês</div>
                            <div class="font-bold text-sm text-up">{{ number_format($writ->actualProfitPercentagePerMonth(), 2, ',', '.') }}%</div>
                        </div>
                    @else
                        <div>
                            <div class="text-xs text-mono-500 mb-1">Recebimento estimado</div>
                            <div class="font-bold text-sm text-mono-900">R$ {{ number_format($writ->estimated_receipt_amount, 2, ',', '.') }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-mono-500 mb-1">Prazo estimado</div>
                            <div class="font-bold text-sm text-mono-900">{{ $writ->estimated_months ?? '—' }} meses</div>
                        </div>
                        <div class="col-span-2 lg:col-span-1 border-t border-mono-100 pt-3 mt-1 lg:border-t-0 lg:pt-0 lg:mt-0">
                            <div class="text-xs text-mono-500 mb-1">Lucro estimado (R$)</div>
                            <div class="font-bold text-sm text-up">R$ {{ number_format($writ->estimatedProfit(), 2, ',', '.') }}</div>
                        </div>
                        <div class="col-span-2 lg:col-span-1 border-t border-mono-100 pt-3 mt-1 lg:border-t-0 lg:pt-0 lg:mt-0">
                            <div class="text-xs text-mono-500 mb-1">Lucro estimado (%)</div>
                            <div class="font-bold text-sm text-up">{{ number_format($writ->estimatedProfitPercentage(), 2, ',', '.') }}%</div>
                        </div>
                        <div class="col-span-2 lg:col-span-1 border-t border-mono-100 pt-3 mt-1 lg:border-t-0 lg:pt-0 lg:mt-0">
                            <div class="text-xs text-mono-500 mb-1">Lucro estimado / Mês</div>
                            <div class="font-bold text-sm text-up">{{ number_format($writ->estimatedProfitPercentagePerMonth(), 2, ',', '.') }}%</div>
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <button class="inline-flex items-center gap-2 rounded-lg border border-primary-500 px-4 py-2 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition-colors">
                        <span class="material-icons-outlined text-[18px]">visibility</span> Ver cálculo
                    </button>
                    <a href="#" class="inline-flex items-center gap-2 text-sm font-medium text-mono-500 hover:text-mono-900 transition-colors">
                        <span class="material-icons-outlined text-[18px]">receipt</span> Exibir requisitório
                    </a>
                </div>
            </div>
            
            <div class="flex justify-end mt-2">
                <button
                    wire:click="delete"
                    wire:confirm="Tem certeza que deseja excluir este requisitório? Esta ação não pode ser desfeita."
                    class="text-xs text-mono-400 hover:text-red-500 underline transition-colors"
                >
                    Excluir requisitório permanentemente
                </button>
            </div>
        </x-fx.card>

        <!-- Direita: Mover para outra etapa -->
        <x-fx.card class="h-fit">
            <div class="flex items-center gap-3 mb-6">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-primary-500 shrink-0">
                    <span class="material-icons-outlined text-[18px]">arrow_forward</span>
                </span>
                <h3 class="text-lg font-bold text-mono-900">Mover para outra etapa</h3>
            </div>

            <form wire:submit="transition" class="flex flex-col gap-5">
                <div>
                    <label class="block text-sm font-medium text-mono-700 mb-1">Nova etapa</label>
                    <select wire:model.live="transitionTo" class="fx-form-field">
                        <option value="">Selecione a etapa</option>
                        @foreach (\App\Domains\Writs\Models\Writ::STAGES as $s)
                            @if ($s !== $writ->stage)
                                <option value="{{ $s }}">{{ \App\Domains\Writs\Models\Writ::STAGE_LABELS[$s] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                @if ($transitionTo === 'pending')
                    <x-fx.input label="Data da cessão" type="datetime-local" wire:model="transition_cession_at" />
                @endif

                @if ($transitionTo === 'paid')
                    <x-fx.input label="Data do pagamento" type="date" wire:model="transition_paid_at" />
                    <x-fx.input label="Valor pago" type="text" x-money wire:model="transition_paid_amount" />
                    <x-fx.input label="Despesas cartorais" type="text" x-money wire:model="transition_notary_expenses" />
                    <x-fx.input label="Outras despesas" type="text" x-money wire:model="transition_other_expenses" />
                    <div>
                        <label class="block text-sm font-medium text-mono-700 mb-1">Conta de origem</label>
                        <select wire:model="transition_source_account" class="fx-form-field">
                            <option value="">—</option>
                            @foreach ($accounts as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($transitionTo === 'petitioning')
                    <x-fx.input label="Data e hora do peticionamento" type="datetime-local" wire:model="transition_petitioned_at" required />
                @endif

                @if ($transitionTo === 'finalized')
                    <x-fx.input label="Data do recebimento" type="date" wire:model="transition_finalized_at" />
                    <x-fx.input label="Valor recebido" type="text" x-money wire:model="transition_actual_receipt_amount" />
                    <div>
                        <label class="block text-sm font-medium text-mono-700 mb-1">Conta de destino</label>
                        <select wire:model="transition_destination_account" class="fx-form-field">
                            <option value="">—</option>
                            @foreach ($accounts as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-mono-700 mb-1">Nota da transição (opcional)</label>
                    <textarea wire:model="transition_notes" class="fx-form-field" rows="4" placeholder="Digite uma observação (opcional)..."></textarea>
                </div>

                <button type="submit" class="w-full rounded-lg bg-primary-500 px-4 py-3 text-sm font-bold text-white hover:bg-primary-600 transition-colors disabled:opacity-50 flex items-center justify-center gap-2" @disabled(! $transitionTo)>
                    Confirmar <span class="material-icons-outlined text-[18px]">arrow_forward</span>
                </button>
            </form>
        </x-fx.card>
    </div>



    <!-- Histórico de transições -->
    <x-fx.card>
        <div class="flex items-center gap-2 mb-6">
            <span class="material-icons-outlined text-primary-500">history</span>
            <h3 class="text-lg font-bold text-mono-900">Histórico de transições</h3>
        </div>
        
        @if ($history->isEmpty())
            <div class="text-sm text-mono-600">Sem histórico.</div>
        @else
            <div class="relative ml-2">
                <!-- Linha vertical -->
                <div class="absolute left-[5px] top-2 bottom-2 w-[2px] bg-mono-200"></div>
                
                <ul class="flex flex-col gap-6 relative">
                    @foreach ($history as $h)
                        <li class="flex items-start gap-4">
                            <div class="relative z-10 mt-1.5 w-3 h-3 rounded-full bg-primary-500 ring-4 ring-white shrink-0"></div>
                            <div class="flex-1 flex flex-col md:flex-row md:justify-between md:items-start border-b border-mono-100 pb-4 last:border-0 last:pb-0">
                                <div>
                                    <div class="text-sm font-bold text-mono-900">
                                        {{ $h->from_stage ? Writ::STAGE_LABELS[$h->from_stage] : 'Criado' }}
                                        @if ($h->from_stage) <span class="text-mono-400 font-normal mx-1">→</span> @endif
                                        {{ Writ::STAGE_LABELS[$h->to_stage] }}
                                    </div>
                                    @if ($h->user)<div class="text-xs text-mono-500 mt-1">por {{ $h->user->name }}</div>@endif
                                    @if ($h->notes)<div class="text-sm text-mono-600 mt-2 bg-mono-50 p-2 rounded">{{ $h->notes }}</div>@endif
                                </div>
                                <div class="text-xs text-mono-500 font-medium whitespace-nowrap mt-2 md:mt-0">{{ $h->transitioned_at->format('d/m/Y H:i') }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-fx.card>

    <!-- Lançamentos vinculados -->
    @if ($transactions->isNotEmpty())
        <x-fx.card>
            <div class="flex items-center gap-2 mb-6">
                <span class="material-icons-outlined text-primary-500">receipt_long</span>
                <h3 class="text-lg font-bold text-mono-900">Lançamentos vinculados</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-mono-500 font-semibold border-b border-mono-100 bg-mono-50">
                        <tr>
                            <th class="px-4 py-3 rounded-tl-lg">DATA</th>
                            <th class="px-4 py-3">TIPO</th>
                            <th class="px-4 py-3">DESCRIÇÃO</th>
                            <th class="px-4 py-3 text-right rounded-tr-lg">VALOR</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-mono-100">
                        @foreach ($transactions as $t)
                            <tr class="hover:bg-mono-50/50 transition-colors">
                                <td class="px-4 py-4 text-mono-900 font-medium">{{ $t->date->format('d/m/Y') }}</td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $t->type === 'income' ? 'bg-blue-100 text-blue-700' : 'bg-mono-100 text-mono-700' }}">
                                        {{ $t->type === 'income' ? 'Receita' : 'Despesa' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-mono-900">{{ $t->description }}</td>
                                <td class="px-4 py-4 text-right font-bold {{ $t->type === 'income' ? 'text-mono-900' : 'text-mono-900' }}">
                                    R$ {{ number_format($t->amount, 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-fx.card>
    @endif
</div>
