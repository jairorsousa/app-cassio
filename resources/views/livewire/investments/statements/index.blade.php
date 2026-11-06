<?php

use App\Domains\Investments\Models\BrokerStatementImport;
use App\Domains\Investments\Models\BrokerStatementRow;
use App\Domains\Investments\Services\Statements\BrokerStatementImportService;
use App\Domains\Investments\Services\Statements\BrokerStatementParser;
use App\Domains\Investments\Services\Statements\StatementAiAssistant;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component {
    use WithFileUploads;

    public $file = null;

    public string $broker = '';

    public ?int $importId = null;

    public array $mapping = [];

    public ?string $message = null;

    public ?string $error = null;

    public function mount(): void
    {
        $open = BrokerStatementImport::whereIn('status', ['draft', 'needs_review'])->where('expires_at', '>', now())->where('created_by', auth()->id())->latest('id')->first();
        if ($open) {
            $this->load($open);
        }
    }

    private function load(BrokerStatementImport $import): void
    {
        $this->importId = $import->id;
        $this->mapping = $import->mapping ?? [];
    }

    private function current(): ?BrokerStatementImport
    {
        return $this->importId ? BrokerStatementImport::find($this->importId) : null;
    }

    private function run(callable $fn): void
    {
        $this->error = null;
        $this->message = null;
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function upload(BrokerStatementImportService $service): void
    {
        $this->validate([
            'broker' => ['required', 'string', 'max:80'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,pdf', 'max:10240'],
        ], ['broker.required' => 'Informe a corretora/instituição.']);
        $this->run(function () use ($service) {
            $import = $service->stage($this->file, $this->broker, auth()->id());
            $this->load($import);
            $this->file = null;
            $this->message = 'Prévia criada. Nada foi gravado em Investimentos ou no Financeiro.';
        });
    }

    public function applyMapping(BrokerStatementImportService $service): void
    {
        $this->run(function () use ($service) {
            $service->remap($this->current(), array_filter($this->mapping));
            $this->message = 'Mapeamento aplicado à prévia.';
        });
    }

    public function askAi(StatementAiAssistant $ai): void
    {
        $import = $this->current();
        if (! $ai->enabled()) {
            $this->error = 'IA não configurada neste servidor: use o mapeamento manual.';

            return;
        }
        $sample = $import->rows()->limit(5)->pluck('raw')->all();
        $s = $ai->suggestMapping($import->headers ?? [], $sample);
        if (! $s) {
            $this->error = 'A IA não respondeu; siga com o mapeamento manual.';

            return;
        }
        $import->update(['ai_suggestion' => $s, 'ai_provider' => $ai->provider()]);
        $this->mapping = array_merge($this->mapping, $s['mapping']);
        $this->message = 'Sugestão da IA carregada nos campos. Revise e clique em "Aplicar mapeamento" (nada foi aplicado ainda).';
    }

    public function setRow(int $rowId, string $field, $value, BrokerStatementImportService $service): void
    {
        $row = BrokerStatementRow::where('import_id', $this->importId)->findOrFail($rowId);
        $this->run(fn () => $service->updateRow($row, [$field => $field === 'force_import' || $field === 'excluded' ? (bool) $value : $value]));
    }

    public function confirmImport(BrokerStatementImportService $service): void
    {
        $import = $this->current();
        if (! $import) {
            $this->error = 'Prévia não encontrada.';

            return;
        }
        $this->run(function () use ($service, $import) {
            $r = $service->confirm($import, auth()->id());
            $this->importId = null;
            $this->mapping = [];
            $this->message = "Importação confirmada: {$r['imported']} provento(s)/aluguel importados, {$r['skipped']} duplicado(s) ignorados, {$r['pending_manual']} operação(ões)/transferência(s) para registrar manualmente.";
        });
    }

    public function discard(BrokerStatementImportService $service): void
    {
        if ($import = $this->current()) {
            $service->discard($import);
        }
        $this->importId = null;
        $this->mapping = [];
        $this->message = 'Prévia descartada e arquivo apagado.';
    }

    public function with(StatementAiAssistant $ai): array
    {
        $import = $this->current();

        return [
            'import' => $import,
            'rows' => $import ? $import->rows()->with('duplicateOf')->limit(500)->get() : collect(),
            'fields' => BrokerStatementParser::FIELDS,
            'kinds' => BrokerStatementRow::KINDS,
            'aiEnabled' => $ai->enabled(),
            'history' => BrokerStatementImport::whereIn('status', ['confirmed', 'discarded', 'expired'])->latest('id')->limit(10)->get(),
        ];
    }
}; ?>

<x-slot name="header">Investimentos</x-slot>

<div class="investment-area space-y-6">
    <x-investments.subnav />
    <div>
        <h2 class="text-2xl font-bold tracking-tight">Extratos de corretoras</h2>
        <p class="mt-2 text-sm text-mono-600">Envie o extrato original (XLSX ou CSV; PDF só com texto). Você revisa a prévia, ajusta o mapeamento e confirma. Só proventos e aluguel viram eventos em Investimentos, sem lançamentos no Financeiro; compras, vendas e transferências ficam como pendências para registro manual. O arquivo fica privado e é apagado ao confirmar, descartar ou em {{ \App\Domains\Investments\Models\BrokerStatementImport::TTL_HOURS }} h.</p>
        <p class="mt-1 text-xs text-mono-600">Layouts por corretora (BTG, XP, BB, Itaú) ainda não validados com arquivos reais: o mapeamento é genérico por nome de coluna. {{ $aiEnabled ? 'IA configurada: pode sugerir o mapeamento enviando cabeçalho + 5 linhas de amostra; nunca confirma sozinha.' : 'IA não configurada: nada é enviado a terceiros.' }}</p>
    </div>

    @if ($message)<x-jr.alert variant="success">{{ $message }}</x-jr.alert>@endif
    @if ($error)<x-jr.alert variant="error">{{ $error }}</x-jr.alert>@endif

    @if (! $import)
        <x-jr.card>
            <form wire:submit="upload" class="grid gap-4 md:grid-cols-3 md:items-end">
                <label class="text-sm">Corretora/instituição<input type="text" wire:model="broker" class="mt-1 w-full rounded-lg border-mono-200" placeholder="Ex.: BTG Pactual">@error('broker')<span class="text-xs text-down">{{ $message }}</span>@enderror</label>
                <label class="text-sm">Arquivo (XLSX, CSV ou PDF)<input type="file" wire:model="file" accept=".xlsx,.csv,.txt,.pdf" class="mt-1 w-full">@error('file')<span class="text-xs text-down">{{ $message }}</span>@enderror</label>
                <x-jr.button type="submit">Gerar prévia</x-jr.button>
            </form>
        </x-jr.card>
    @else
        <x-jr.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h3 class="font-bold">Prévia: {{ $import->file_name }} · {{ $import->broker_name }}</h3><p class="text-xs text-mono-600">Expira {{ $import->expires_at?->timezone('America/Fortaleza')->format('d/m/Y H:i') }} · {{ strtoupper($import->file_kind) }}</p></div>
                <button type="button" wire:click="discard" class="text-sm font-semibold text-down">Descartar</button>
            </div>
            @if ($import->status === 'needs_review')
                <x-jr.alert variant="warning" class="mt-4">Precisa revisão: {{ $import->summary['message'] ?? 'PDF sem leitura automática.' }}</x-jr.alert>
            @else
                <div class="mt-4 grid gap-3 md:grid-cols-4">
                    @foreach ($fields as $field => $label)
                        <label class="text-xs">{{ $label }}
                            <select wire:model="mapping.{{ $field }}" class="mt-1 w-full rounded-lg border-mono-200 text-sm">
                                <option value="">—</option>
                                @foreach ($import->headers ?? [] as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-jr.button type="button" wire:click="applyMapping">Aplicar mapeamento</x-jr.button>
                    @if ($aiEnabled)<button type="button" wire:click="askAi" class="text-sm font-semibold text-primary-500">Pedir sugestão à IA</button>@endif
                </div>
                @if ($import->ai_suggestion)<p class="mt-2 text-xs text-mono-600">Sugestão IA ({{ $import->ai_provider }}): {{ $import->ai_suggestion['notes'] ?? '' }}</p>@endif
                @php $s = $import->summary ?? []; @endphp
                @if ($rec = $s['reconciliation'] ?? null)
                    <x-jr.alert :variant="$rec['ok'] ? 'success' : 'error'" class="mt-4">Layout BTG (Conta Investimento, calibrado com 1 arquivo). Saldo inicial R$ {{ number_format($rec['opening'], 2, ',', '.') }} + créditos R$ {{ number_format($rec['credits'], 2, ',', '.') }} − débitos R$ {{ number_format($rec['debits'], 2, ',', '.') }} = R$ {{ number_format($rec['computed_final'], 2, ',', '.') }} {{ $rec['ok'] ? '(reconcilia com o saldo corrido e os totais do PDF)' : '(NÃO reconcilia: '.$rec['line_mismatches'].' linha(s) com saldo divergente)' }}.@if ($rec['printed_final_differs']) "Saldo Final" impresso R$ {{ number_format($rec['printed_final'], 2, ',', '.') }} difere do saldo corrido (ex.: rendimento do saldo remunerado lançado após o fechamento).@endif</x-jr.alert>
                @endif
                <p class="mt-4 text-sm">Linhas: <strong>{{ $s['rows'] ?? 0 }}</strong> · prontas: <strong>{{ $s['by_status']['ready'] ?? 0 }}</strong> (R$ {{ number_format($s['ready_net'] ?? 0, 2, ',', '.') }}) · duplicadas: {{ $s['by_status']['duplicate'] ?? 0 }} · possíveis duplicadas: {{ $s['by_status']['duplicate_candidate'] ?? 0 }} · pendentes manuais: {{ $s['by_status']['pending_manual'] ?? 0 }} · erros: {{ $s['by_status']['error'] ?? 0 }} · excluídas: {{ $s['by_status']['excluded'] ?? 0 }}</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead><tr class="text-left text-mono-600"><th>#</th><th>Data</th><th>Ticker</th><th>Tipo</th><th class="text-right">Qtd</th><th class="text-right">Bruto</th><th class="text-right">IR</th><th class="text-right">Líquido</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-mono-100" wire:key="r{{ $row->id }}">
                                <td>{{ $row->row_number }}</td>
                                <td>{{ $row->date?->format('d/m/Y') ?? '—' }}</td>
                                <td><input type="text" value="{{ $row->ticker }}" maxlength="12" class="w-20 rounded border-mono-200 text-xs uppercase" wire:change="setRow({{ $row->id }}, 'ticker', $event.target.value.toUpperCase())" placeholder="—"></td>
                                <td><select class="rounded border-mono-200 text-xs" wire:change="setRow({{ $row->id }}, 'kind', $event.target.value)">@foreach ($kinds as $k => $l)<option value="{{ $k }}" @selected($row->kind === $k)>{{ $l }}</option>@endforeach</select><div class="text-mono-500">{{ $row->source_type_label }}</div></td>
                                <td class="text-right">{{ $row->quantity !== null ? rtrim(rtrim(number_format($row->quantity, 6, ',', '.'), '0'), ',') : '—' }}</td>
                                <td class="text-right">{{ $row->gross_amount !== null ? number_format($row->gross_amount, 2, ',', '.') : '—' }}</td>
                                <td class="text-right">{{ $row->withholding_tax !== null ? number_format($row->withholding_tax, 2, ',', '.') : '—' }}</td>
                                <td class="text-right">{{ $row->net_amount !== null ? number_format($row->net_amount, 2, ',', '.') : '—' }}</td>
                                <td>{{ ['ready' => 'Pronta', 'error' => 'Erro', 'duplicate' => 'Duplicada (já importada)', 'duplicate_candidate' => 'Possível duplicada', 'pending_manual' => 'Registrar manualmente', 'excluded' => 'Excluída', 'linked' => 'Vinculado (IR da taxa)', 'pending' => 'Pendente'][$row->status] ?? $row->status }}@foreach ($row->messages ?? [] as $m)<div class="text-mono-500">{{ $m }}</div>@endforeach</td>
                                <td class="whitespace-nowrap">
                                    @if ($row->status === 'duplicate_candidate')<button type="button" class="text-primary-500" wire:click="setRow({{ $row->id }}, 'force_import', 1)">É outro evento</button>@endif
                                    @if ($row->status !== 'excluded')<button type="button" class="ml-2 text-down" wire:click="setRow({{ $row->id }}, 'excluded', 1)">Excluir</button>@else<button type="button" class="text-primary-500" wire:click="setRow({{ $row->id }}, 'excluded', 0)">Incluir</button>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4"><x-jr.button type="button" wire:click="confirmImport" wire:confirm="Importar {{ $s['by_status']['ready'] ?? 0 }} provento(s)/aluguel para Investimentos? Nenhum lançamento será criado no Financeiro.">Confirmar importação</x-jr.button></div>
            @endif
        </x-jr.card>
    @endif

    @if ($history->isNotEmpty())
        <x-jr.card>
            <h3 class="mb-3 font-bold">Últimos lotes</h3>
            @foreach ($history as $h)
                <p class="text-xs text-mono-600">#{{ $h->id }} · {{ $h->broker_name }} · {{ $h->file_name }} · {{ ['confirmed' => 'confirmado', 'discarded' => 'descartado', 'expired' => 'expirado'][$h->status] ?? $h->status }} · {{ $h->updated_at->timezone('America/Fortaleza')->format('d/m/Y H:i') }}</p>
            @endforeach
        </x-jr.card>
    @endif
</div>
