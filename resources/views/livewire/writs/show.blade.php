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
    public string $transition_paid_at = '';
    public string $transition_paid_amount = '';
    public ?int $transition_source_account = null;
    public string $transition_finalized_at = '';
    public string $transition_actual_receipt_amount = '';
    public ?int $transition_destination_account = null;
    public string $transition_notes = '';

    public function mount(Writ $writ): void
    {
        $this->writ = $writ->load('history.user', 'transactions', 'assignors.contact');
        $this->transition_paid_at = now()->format('Y-m-d');
        $this->transition_finalized_at = now()->format('Y-m-d');
        $this->transition_paid_amount = (string) $writ->paid_amount;
        $this->transition_source_account = $writ->source_bank_account_id;
        $this->transition_destination_account = $writ->destination_bank_account_id;
    }

    public function transition(WritService $service)
    {
        $this->validate([
            'transitionTo' => 'required|in:'.implode(',', Writ::STAGES),
        ]);

        $context = ['notes' => $this->transition_notes ?: null];

        if ($this->transitionTo === 'paid') {
            $context['paid_at'] = $this->transition_paid_at;
            $context['paid_amount'] = (float) $this->transition_paid_amount;
            $context['source_bank_account_id'] = $this->transition_source_account;
        }

        if ($this->transitionTo === 'finalized') {
            $context['finalized_at'] = $this->transition_finalized_at;
            $context['actual_receipt_amount'] = (float) $this->transition_actual_receipt_amount;
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

<x-slot name="header">{{ $writ->process_number ?: '#'.$writ->id }} · {{ $writ->stageLabel() }}</x-slot>

<div class="flex flex-col gap-md">
    @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif
    @if (session('error'))<x-fx.alert variant="error">{{ session('error') }}</x-fx.alert>@endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-md">
        <x-fx.card class="lg:col-span-2">
            <h3 class="text-md font-semibold mb-sm">Identificação</h3>
            <div class="grid grid-cols-2 gap-xs text-sm">
                <div><span class="text-mono-600">Tipo:</span> {{ $writ->type === 'rpv' ? 'RPV' : 'Precatório' }}</div>
                <div><span class="text-mono-600">Etapa:</span> <span class="fx-badge">{{ $writ->stageLabel() }}</span></div>
                <div><span class="text-mono-600">Processo:</span> {{ $writ->process_number ?: '—' }}</div>
                <div><span class="text-mono-600">Vara/Tribunal:</span> {{ $writ->court ?: '—' }}</div>
                <div><span class="text-mono-600">Ente devedor:</span> {{ $writ->debtor_entity ?: '—' }}</div>
                <div><span class="text-mono-600">Natureza:</span> {{ $writ->credit_nature ?: '—' }}</div>
            </div>

            <h3 class="text-md font-semibold mt-md mb-sm">Cedentes</h3>
            @if ($writ->assignors->isEmpty())
                <div class="text-sm text-mono-600">Nenhum cedente vinculado.</div>
            @else
                <div class="flex flex-col gap-xs">
                    @foreach ($writ->assignors as $a)
                        <div class="flex items-start gap-sm text-sm">
                            <span class="fx-badge shrink-0">{{ $a->role === 'advogado' ? 'Advogado' : 'Parte' }}</span>
                            <div>
                                <div class="font-medium">{{ $a->contact->name }}</div>
                                <div class="text-xxs text-mono-600">
                                    {{ implode(' · ', array_filter([$a->contact->document, $a->contact->phone])) ?: '—' }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <h3 class="text-md font-semibold mt-md mb-sm">Valores</h3>
            <div class="grid grid-cols-3 gap-xs text-sm">
                <div><span class="text-mono-600">Face:</span> R$ {{ number_format($writ->face_value, 2, ',', '.') }}</div>
                <div><span class="text-mono-600">Pago:</span> R$ {{ number_format($writ->paid_amount, 2, ',', '.') }}</div>
                <div><span class="text-mono-600">Deságio:</span> {{ number_format($writ->discount_percentage, 2, ',', '.') }}%</div>
                <div><span class="text-mono-600">Receb. estimado:</span> R$ {{ number_format($writ->estimated_receipt_amount, 2, ',', '.') }}</div>
                <div><span class="text-mono-600">Prazo:</span> {{ $writ->estimated_months ?? '—' }} meses</div>
                <div><span class="text-mono-600">Lucro estimado:</span> R$ {{ number_format($writ->estimatedProfit(), 2, ',', '.') }}</div>
            </div>

            @if ($writ->actual_receipt_amount)
                <h3 class="text-md font-semibold mt-md mb-sm">Rentabilidade realizada</h3>
                <div class="grid grid-cols-3 gap-xs text-sm">
                    <div><span class="text-mono-600">Recebido:</span> R$ {{ number_format($writ->actual_receipt_amount, 2, ',', '.') }}</div>
                    <div><span class="text-mono-600">Lucro real:</span> <span class="font-semibold text-system-up">R$ {{ number_format($profitability['profit_amount'], 2, ',', '.') }}</span></div>
                    <div><span class="text-mono-600">% real:</span> {{ number_format($profitability['profit_percentage'], 2, ',', '.') }}%</div>
                    <div><span class="text-mono-600">Dias decorridos:</span> {{ $profitability['days_elapsed'] ?? '—' }}</div>
                    <div><span class="text-mono-600">% ao mês:</span> {{ $profitability['monthly_rate'] !== null ? number_format($profitability['monthly_rate'], 3, ',', '.').'%' : '—' }}</div>
                </div>
            @endif

            <div class="mt-md flex items-center justify-between">
                <div class="flex gap-xs">
                    <a href="{{ route('writs.edit', $writ) }}" class="fx-btn fx-btn--standard fx-btn--sm">Editar dados</a>
                    <a href="{{ route('writs.kanban') }}" class="fx-btn fx-btn--text fx-btn--sm">← Voltar ao kanban</a>
                </div>
                <button
                    wire:click="delete"
                    wire:confirm="Tem certeza que deseja excluir este requisitório? Todas as transações bancárias e eventos vinculados serão removidos permanentemente. Esta ação não pode ser desfeita."
                    class="fx-btn fx-btn--sm"
                    style="background: var(--colors-system-error-bg); color: var(--colors-system-error);"
                >
                    🗑 Excluir requisitório
                </button>
            </div>
        </x-fx.card>

        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Mover para outra etapa</h3>
            <form wire:submit="transition" class="flex flex-col gap-sm">
                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Nova etapa</label>
                    <select wire:model.live="transitionTo" class="fx-form-field">
                        <option value="">—</option>
                        @foreach (\App\Domains\Writs\Models\Writ::STAGES as $s)
                            @if ($s !== $writ->stage)
                                <option value="{{ $s }}">{{ \App\Domains\Writs\Models\Writ::STAGE_LABELS[$s] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                @if ($transitionTo === 'paid')
                    <x-fx.input label="Data do pagamento" type="date" wire:model="transition_paid_at" />
                    <x-fx.input label="Valor pago" type="text" x-money wire:model="transition_paid_amount" />
                    <div>
                        <label class="block text-xxs text-mono-600 mb-xxxs">Conta de origem</label>
                        <select wire:model="transition_source_account" class="fx-form-field">
                            <option value="">—</option>
                            @foreach ($accounts as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($transitionTo === 'finalized')
                    <x-fx.input label="Data do recebimento" type="date" wire:model="transition_finalized_at" />
                    <x-fx.input label="Valor recebido" type="text" x-money wire:model="transition_actual_receipt_amount" />
                    <div>
                        <label class="block text-xxs text-mono-600 mb-xxxs">Conta de destino</label>
                        <select wire:model="transition_destination_account" class="fx-form-field">
                            <option value="">—</option>
                            @foreach ($accounts as $a)
                                <option value="{{ $a->id }}">{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="block text-xxs text-mono-600 mb-xxxs">Notas da transição</label>
                    <textarea wire:model="transition_notes" class="fx-form-field" rows="2"></textarea>
                </div>

                <button type="submit" class="fx-btn fx-btn--primary" @disabled(! $transitionTo)>Confirmar</button>
            </form>
        </x-fx.card>
    </div>

    <x-fx.card>
        <h3 class="text-md font-semibold mb-sm">Histórico de transições</h3>
        @if ($history->isEmpty())
            <div class="text-sm text-mono-600">Sem histórico.</div>
        @else
            <ul class="flex flex-col gap-xxs">
                @foreach ($history as $h)
                    <li class="flex justify-between items-start py-xxs border-b border-mono-100">
                        <div>
                            <div class="text-sm">
                                {{ $h->from_stage ? Writ::STAGE_LABELS[$h->from_stage] : 'Criado' }}
                                @if ($h->from_stage) → @endif
                                <strong>{{ Writ::STAGE_LABELS[$h->to_stage] }}</strong>
                            </div>
                            @if ($h->notes)<div class="text-xxs text-mono-600 mt-xxxs">{{ $h->notes }}</div>@endif
                            @if ($h->user)<div class="text-xxs text-mono-600">por {{ $h->user->name }}</div>@endif
                        </div>
                        <div class="text-xxs text-mono-600">{{ $h->transitioned_at->format('d/m/Y H:i') }}</div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-fx.card>

    @if ($transactions->isNotEmpty())
        <x-fx.card>
            <h3 class="text-md font-semibold mb-sm">Lançamentos vinculados</h3>
            <table class="fx-table w-full text-sm">
                <thead><tr><th class="text-left">Data</th><th class="text-left">Tipo</th><th class="text-left">Descrição</th><th class="text-right">Valor</th></tr></thead>
                <tbody>
                    @foreach ($transactions as $t)
                        <tr>
                            <td>{{ $t->date->format('d/m/Y') }}</td>
                            <td>{{ $t->type === 'income' ? 'Receita' : 'Despesa' }}</td>
                            <td>{{ $t->description }}</td>
                            <td class="text-right font-semibold {{ $t->type === 'income' ? 'text-system-up' : 'text-system-down' }}">
                                R$ {{ number_format($t->amount, 2, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-fx.card>
    @endif
</div>
