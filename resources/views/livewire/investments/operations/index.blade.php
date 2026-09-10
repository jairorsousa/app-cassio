<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetClass;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Services\BrapiQuoteProvider;
use App\Domains\Investments\Support\MarketTicker;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    #[Url]
    public string $assetFilter = '';
    #[Url]
    public string $typeFilter = '';
    #[Url]
    public string $accountFilter = '';

    public string $from = '';
    public string $to = '';

    public bool $showFormModal = false;
    public string $formStep = 'choose';
    public ?int $editingId = null;

    public ?int $asset_id = null;
    public ?int $asset_class_id = null;
    public string $ticker = '';
    public string $lookupStatus = '';
    public bool $willCreateAsset = false;
    public string $assetName = '';
    public string $assetSector = '';
    public string $resolvedClassSlug = '';
    public bool $priceFromMarket = false;
    public string $opDate = '';
    public string $opType = 'buy';
    public string $quantity = '';
    public string $unit_price = '';
    public string $fees = '0';
    public ?int $bank_account_id = null;
    public string $opNotes = '';

    public function mount(): void
    {
        $this->opDate = now()->format('Y-m-d');
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to', 'assetFilter', 'typeFilter', 'accountFilter'])) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'assetFilter', 'typeFilter', 'accountFilter']);
        $this->resetPage();
    }

    public function filterByAccount(int $id = 0): void
    {
        $this->accountFilter = $id > 0 ? (string) $id : '';
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->formStep = 'choose';
        $this->showFormModal = true;
        if ($this->accountFilter !== '') {
            $this->bank_account_id = (int) $this->accountFilter;
        } elseif (! $this->bank_account_id) {
            $ids = BankAccount::active()->investment()->pluck('id');
            if ($ids->count() === 1) {
                $this->bank_account_id = (int) $ids->first();
            }
        }
    }

    public function chooseType(string $type): void
    {
        if (! in_array($type, ['buy', 'sell'], true)) {
            return;
        }

        $this->opType = $type;
        $this->formStep = 'form';
        $this->resetValidation();
        $this->reset(['asset_id', 'asset_class_id', 'ticker', 'lookupStatus', 'willCreateAsset', 'assetName', 'assetSector', 'resolvedClassSlug', 'priceFromMarket']);
    }

    public function backToType(): void
    {
        if ($this->editingId) {
            return;
        }

        $this->formStep = 'choose';
        $this->resetValidation();
        $this->reset(['asset_id', 'asset_class_id', 'ticker', 'lookupStatus', 'willCreateAsset', 'assetName', 'assetSector', 'resolvedClassSlug', 'priceFromMarket', 'quantity', 'unit_price']);
        $this->fees = '0';
    }

    public function rules(): array
    {
        $accountRule = Rule::exists('bank_accounts', 'id')->where(function ($query) {
            $query->where('status', true)->whereNull('deleted_at');
            $query->where(function ($query) {
                $query->where('type', 'investment');
                if ($this->editingId && $this->bank_account_id) {
                    $query->orWhere('id', $this->bank_account_id);
                }
            });
        });

        return [
            'ticker' => 'required|string|max:20',
            'asset_id' => $this->willCreateAsset ? 'nullable' : 'required|exists:assets,id',
            'asset_class_id' => $this->opType === 'buy' && $this->willCreateAsset
                ? 'required|exists:asset_classes,id'
                : 'nullable|exists:asset_classes,id',
            'assetName' => $this->opType === 'buy' ? 'required|string|max:200' : 'nullable|string|max:200',
            'assetSector' => 'nullable|string|max:120',
            'opDate' => 'required|date|before_or_equal:today',
            'opType' => 'required|in:buy,sell',
            'quantity' => 'required|numeric|min:0.000001',
            'unit_price' => 'required|numeric|min:0',
            'fees' => 'required|numeric|min:0',
            'bank_account_id' => ['required', $accountRule],
            'opNotes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'ticker.required' => 'Informe o código do ativo.',
            'asset_id.required' => 'Informe o código do ativo.',
            'asset_class_id.required' => 'Selecione o tipo de ativo.',
            'assetName.required' => 'Informe o nome do ativo.',
            'bank_account_id.required' => 'Selecione a conta de investimento (corretora) desta operação.',
            'bank_account_id.exists' => 'Selecione uma conta de investimento ativa.',
        ];
    }

    public function edit(int $id): void
    {
        $this->resetValidation();
        $this->showFormModal = true;
        $this->formStep = 'form';
        $op = AssetOperation::with('asset.position', 'asset.assetClass')->findOrFail($id);
        $this->editingId = $op->id;
        $this->asset_id = $op->asset_id;
        $this->asset_class_id = $op->asset?->asset_class_id;
        $this->ticker = (string) ($op->asset?->ticker ?? '');
        $this->assetName = (string) ($op->asset?->name ?? '');
        $this->assetSector = (string) ($op->asset?->sector ?? '');
        $this->priceFromMarket = false;
        if ($op->asset && $op->type === 'sell') {
            $this->lookupStatus = $this->holdingStatus($op->asset, $this->availableQuantityFor($op->asset));
        } elseif ($op->asset) {
            $this->lookupStatus = $op->asset->name.($op->asset->assetClass?->name ? ' · '.$op->asset->assetClass->name : '');
        } else {
            $this->lookupStatus = '';
        }
        $this->willCreateAsset = false;
        $this->opDate = $op->date->format('Y-m-d');
        $this->opType = $op->type;
        $this->quantity = (string) $op->quantity;
        $this->unit_price = (string) $op->unit_price;
        $this->fees = (string) $op->fees;
        $this->bank_account_id = $op->bank_account_id;
        $this->opNotes = (string) $op->notes;
    }

    public function updatedTicker(): void
    {
        if (! $this->editingId) {
            $this->assetName = '';
            $this->assetSector = '';
            $this->unit_price = '';
            $this->priceFromMarket = false;
        }
        $this->lookupAsset(fillQuote: true);
    }

    public function updatedOpType(): void
    {
        $this->reset(['asset_id', 'lookupStatus', 'willCreateAsset', 'resolvedClassSlug']);
        if (trim($this->ticker) !== '') {
            $this->lookupAsset(fillQuote: $this->opType === 'buy' && ! $this->editingId);
        }
    }

    public function updatedAssetClassId(): void
    {
        if ($this->opType === 'buy' && trim($this->ticker) !== '') {
            $this->lookupAsset();
        }
    }

    public function lookupAsset(bool $fillQuote = false): void
    {
        $this->ticker = strtoupper(trim($this->ticker));
        $this->lookupStatus = '';
        $this->willCreateAsset = false;
        $this->asset_id = null;
        $this->resolvedClassSlug = '';
        $this->resetErrorBag('ticker');

        if ($this->ticker === '') {
            return;
        }

        if ($this->opType === 'sell') {
            $this->lookupSellAsset();

            return;
        }

        $this->lookupBuyAsset($fillQuote);
    }

    public function selectHolding(int $assetId): void
    {
        $asset = Asset::with('position')->findOrFail($assetId);
        $available = $this->availableQuantityFor($asset);
        if ($available <= 0) {
            $this->addError('ticker', 'Você não possui quantidade disponível deste ativo na carteira.');

            return;
        }

        $this->resetErrorBag('ticker');
        $this->willCreateAsset = false;
        $this->asset_id = $asset->id;
        $this->ticker = $asset->ticker;
        $this->assetName = $asset->name;
        $this->assetSector = (string) $asset->sector;
        $this->asset_class_id = $asset->asset_class_id;
        $this->lookupStatus = $this->holdingStatus($asset, $available);
    }

    public function save(): void
    {
        $this->ticker = strtoupper(trim($this->ticker));
        if (! $this->asset_id && trim($this->ticker) !== '') {
            $this->lookupAsset();
        }

        if (! $this->asset_id && ! $this->willCreateAsset) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ticker' => $this->getErrorBag()->first('ticker') ?: 'Informe o código do ativo.',
            ]);
        }

        $data = $this->validate();

        if ($this->opType === 'sell') {
            $this->assertSellableAsset();
        }

        if (! $this->asset_id && $this->willCreateAsset && $this->opType === 'buy') {
            $this->asset_id = $this->createAssetFromLookup()->id;
            $data['asset_id'] = $this->asset_id;
        } elseif ($this->opType === 'buy' && $this->asset_id) {
            $this->syncAssetDetails();
        }

        $qty = (float) $data['quantity'];
        $unit = (float) $data['unit_price'];
        $fees = (float) $data['fees'];
        $total = round($qty * $unit + ($data['opType'] === 'buy' ? $fees : -$fees), 2);

        $payload = [
            'asset_id' => $this->asset_id,
            'date' => $data['opDate'],
            'type' => $data['opType'],
            'quantity' => $qty,
            'unit_price' => $unit,
            'fees' => $fees,
            'total' => $total,
            'bank_account_id' => $data['bank_account_id'],
            'notes' => $data['opNotes'],
        ];

        app(\App\Domains\Investments\Services\InvestmentLedgerService::class)->saveOperation($payload, $this->editingId);

        $this->resetForm();
        session()->flash('status', 'Operação salva.');
    }

    public function delete(int $id): void
    {
        try {
            app(\App\Domains\Investments\Services\InvestmentLedgerService::class)->deleteOperation($id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first());

            return;
        }
        session()->flash('status', 'Operação excluída.');
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->showFormModal = false;
        $this->formStep = 'choose';
        $this->resetValidation();
        $this->reset([
            'editingId', 'asset_id', 'asset_class_id', 'ticker', 'lookupStatus', 'willCreateAsset',
            'assetName', 'assetSector', 'resolvedClassSlug', 'priceFromMarket', 'quantity',
            'unit_price', 'bank_account_id', 'opNotes',
        ]);
        $this->opType = 'buy';
        $this->fees = '0';
        $this->opDate = now()->format('Y-m-d');
    }

    private function lookupBuyAsset(bool $fillQuote = false): void
    {
        $existing = Asset::with('assetClass')->where('ticker', $this->ticker)->first();
        if ($existing) {
            $this->asset_id = $existing->id;
            $this->asset_class_id = $existing->asset_class_id;
            $this->fillAssetIdentity($existing->name, (string) $existing->sector);
            $this->lookupStatus = $existing->name.($existing->assetClass?->name ? ' · '.$existing->assetClass->name : '');
            if ($fillQuote) {
                $this->fillUnitPriceFromMarket();
            }

            return;
        }

        if (! MarketTicker::isListed($this->ticker)) {
            if ($this->asset_class_id) {
                $this->prepareNewAsset($this->ticker, $this->selectedClassSlug(), '');

                return;
            }

            $this->addError('ticker', 'Ativo não cadastrado. Selecione o tipo de ativo ou cadastre o código em Ativos.');

            return;
        }

        $result = app(BrapiQuoteProvider::class)->lookup($this->ticker);
        if ($result === null) {
            if ($this->asset_class_id) {
                $this->prepareNewAsset($this->ticker, $this->selectedClassSlug(), '');

                return;
            }

            $this->addError('ticker', 'Não encontramos esse código na B3. Selecione o tipo de ativo ou cadastre o código em Ativos.');

            return;
        }

        $this->prepareNewAsset($result['name'], $result['class_slug'], (string) ($result['sector'] ?? ''), overwrite: $fillQuote);
        if (! $this->asset_class_id) {
            $this->asset_class_id = $this->classIdFromSlug($result['class_slug']);
        }
        if ($fillQuote) {
            $this->fillUnitPriceFromMarket($result['close'] ?? null);
        }
    }

    private function lookupSellAsset(): void
    {
        $asset = Asset::with('position', 'assetClass')->where('ticker', $this->ticker)->first();
        if ($asset) {
            $available = $this->availableQuantityFor($asset);
            if ($available <= 0) {
                $this->addError('ticker', 'Você não possui quantidade disponível deste ativo na carteira.');

                return;
            }

            $this->asset_id = $asset->id;
            $this->asset_class_id = $asset->asset_class_id;
            $this->fillAssetIdentity($asset->name, (string) $asset->sector);
            $this->lookupStatus = $this->holdingStatus($asset, $available);

            return;
        }

        $hasPartialMatch = Asset::whereHas('position', fn ($q) => $q->where('quantity', '>', 0))
            ->where(fn ($q) => $q->where('ticker', 'like', '%'.$this->ticker.'%')->orWhere('name', 'like', '%'.$this->ticker.'%'))
            ->exists();

        if (! $hasPartialMatch) {
            $this->addError('ticker', 'Você só pode vender ativos que já estão na carteira.');
        }
    }

    private function availableQuantityFor(Asset $asset): float
    {
        $qty = (float) ($asset->position?->quantity ?? 0);
        if ($this->editingId) {
            $op = AssetOperation::find($this->editingId);
            if ($op && $op->type === 'sell' && (int) $op->asset_id === (int) $asset->id) {
                $qty += (float) $op->quantity;
            }
        }

        return $qty;
    }

    private function holdingStatus(Asset $asset, float $available): string
    {
        $avg = (float) ($asset->position?->average_price ?? 0);

        return $asset->name.' · '.number_format($available, 6, ',', '.').' un disponíveis'
            .($avg > 0 ? ' · preço médio R$ '.number_format($avg, 4, ',', '.') : '');
    }

    private function assertSellableAsset(): void
    {
        if (! $this->asset_id) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ticker' => 'Você só pode vender ativos que já estão na carteira.',
            ]);
        }

        $asset = Asset::with('position')->find($this->asset_id);
        if (! $asset || $this->availableQuantityFor($asset) <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ticker' => 'Você só pode vender ativos que já estão na carteira.',
            ]);
        }
    }

    private function createAssetFromLookup(): Asset
    {
        $class = $this->asset_class_id ? AssetClass::find($this->asset_class_id) : null;
        if (! $class) {
            $slug = $this->resolvedClassSlug !== '' ? $this->resolvedClassSlug : 'outros';
            $class = AssetClass::firstOrCreate(
                ['slug' => $slug],
                ['name' => MarketTicker::className($slug), 'status' => true]
            );
        }

        return Asset::firstOrCreate(
            ['ticker' => $this->ticker],
            [
                'name' => $this->assetName !== '' ? $this->assetName : $this->ticker,
                'asset_class_id' => $class->id,
                'sector' => $this->assetSector !== '' ? $this->assetSector : null,
                'status' => true,
            ]
        );
    }

    private function syncAssetDetails(): void
    {
        $asset = Asset::find($this->asset_id);
        if (! $asset) {
            return;
        }

        $name = trim($this->assetName);
        $sector = trim($this->assetSector);
        $payload = [];
        if ($name !== '' && $name !== $asset->name) {
            $payload['name'] = $name;
        }
        if ($sector !== (string) $asset->sector) {
            $payload['sector'] = $sector !== '' ? $sector : null;
        }
        if ($payload !== []) {
            $asset->update($payload);
        }
    }

    private function prepareNewAsset(string $name, string $classSlug, string $sector, bool $overwrite = false): void
    {
        $this->willCreateAsset = true;
        $this->fillAssetIdentity($name, $sector, $overwrite);
        $this->resolvedClassSlug = $classSlug !== '' ? $classSlug : 'outros';
        $className = $this->asset_class_id
            ? AssetClass::find($this->asset_class_id)?->name
            : MarketTicker::className($this->resolvedClassSlug);
        $label = $this->assetName !== '' ? $this->assetName : $name;
        $this->lookupStatus = 'Será cadastrado automaticamente: '.$label.($className ? ' · '.$className : '');
    }

    private function fillAssetIdentity(string $name, string $sector, bool $overwrite = false): void
    {
        if ($overwrite || $this->assetName === '') {
            $this->assetName = $name;
        }
        if ($overwrite || $this->assetSector === '') {
            $this->assetSector = $sector;
        }
    }

    private function fillUnitPriceFromMarket(?float $fallback = null): void
    {
        if ($this->editingId || $this->opType !== 'buy') {
            return;
        }

        $price = null;
        if (MarketTicker::isListed($this->ticker)) {
            $quote = app(BrapiQuoteProvider::class)->quote($this->ticker);
            $price = $quote['price'] ?? null;
        }
        if ((! $price || $price <= 0) && $fallback && $fallback > 0) {
            $price = $fallback;
        }
        if ($price && $price > 0) {
            $this->unit_price = (string) round((float) $price, 4);
            $this->priceFromMarket = true;
        }
    }

    private function selectedClassSlug(): string
    {
        if (! $this->asset_class_id) {
            return 'outros';
        }

        return AssetClass::find($this->asset_class_id)?->slug ?: 'outros';
    }

    private function classIdFromSlug(string $slug): int
    {
        return AssetClass::firstOrCreate(
            ['slug' => $slug],
            ['name' => MarketTicker::className($slug), 'status' => true]
        )->id;
    }

    public function with(): array
    {
        AssetClass::ensureCatalog();

        $q = AssetOperation::with('asset', 'bankAccount');
        if ($this->assetFilter) {
            $q->where('asset_id', $this->assetFilter);
        }
        if ($this->typeFilter) {
            $q->where('type', $this->typeFilter);
        }
        if ($this->accountFilter) {
            $q->where('bank_account_id', $this->accountFilter);
        }

        $brokers = BankAccount::active()->investment()->orderBy('name')->get();
        $accounts = $brokers;
        if ($this->bank_account_id && ! $accounts->contains('id', $this->bank_account_id)) {
            $current = BankAccount::find($this->bank_account_id);
            if ($current) {
                $accounts = $accounts->prepend($current);
            }
        }

        $holdings = collect();
        if ($this->showFormModal && $this->formStep === 'form' && $this->opType === 'sell') {
            $holdings = AssetPosition::with('asset.assetClass')
                ->where(function ($query) {
                    $query->where('quantity', '>', 0);
                    if ($this->editingId && $this->asset_id) {
                        $query->orWhere('asset_id', $this->asset_id);
                    }
                })
                ->get()
                ->sortBy(fn ($p) => $p->asset?->ticker)
                ->values();
        }

        return [
            'operations' => $q->when($this->from, fn ($q) => $q->whereDate('date', '>=', $this->from))->when($this->to, fn ($q) => $q->whereDate('date', '<=', $this->to))->orderByDesc('date')->orderByDesc('id')->paginate(25),
            'assets' => Asset::orderBy('ticker')->get(),
            'classes' => AssetClass::ordered(),
            'brokers' => $brokers,
            'accounts' => $accounts,
            'holdings' => $holdings,
        ];
    }
}; ?>

<x-slot name="header">Investimentos · Operações</x-slot>

<div class="investment-area">
    <x-investments.subnav />
    @if (session('error'))<x-jr.alert variant="error">{{ session('error') }}</x-jr.alert>@endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold">Movimentações</h2>
            <p class="mt-1 text-sm text-mono-600">Compras e vendas pela conta de investimento da corretora, com lançamento no Financeiro.</p>
        </div>
        <x-jr.button wire:click="create"><span class="material-icons-outlined text-[18px]">add</span>Nova movimentação</x-jr.button>
    </div>

    @if ($brokers->isEmpty())
        <x-jr.card>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="font-semibold">Vincule uma corretora para começar</h3>
                    <p class="mt-1 text-sm text-mono-600">Cadastre XP, BTG e demais contas com o tipo <strong>Investimento</strong> no Financeiro. Toda aplicação e resgate sai dessa conta.</p>
                </div>
                <x-jr.button href="{{ route('banking.accounts.index') }}" variant="standard">Cadastrar conta</x-jr.button>
            </div>
        </x-jr.card>
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <button type="button" wire:click="filterByAccount(0)" @class(['rounded-2xl border px-4 py-4 text-left transition-colors', 'border-primary-500 bg-primary-100' => $accountFilter === '', 'border-mono-100 bg-mono-white hover:border-mono-200' => $accountFilter !== ''])>
                <p class="text-xs font-semibold uppercase tracking-wide text-mono-600">Todas as corretoras</p>
                <p class="mt-2 text-lg font-bold">{{ $brokers->count() }} {{ $brokers->count() === 1 ? 'conta' : 'contas' }}</p>
            </button>
            @foreach ($brokers as $account)
                <button type="button" wire:click="filterByAccount({{ $account->id }})" @class(['rounded-2xl border px-4 py-4 text-left transition-colors', 'border-primary-500 bg-primary-100' => $accountFilter === (string) $account->id, 'border-mono-100 bg-mono-white hover:border-mono-200' => $accountFilter !== (string) $account->id])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-mono-600">{{ $account->bank ?: 'Corretora' }}</p>
                    <p class="mt-2 truncate text-lg font-bold">{{ $account->name }}</p>
                    <p class="mt-1 text-sm text-mono-600">R$ {{ number_format($account->balance(), 2, ',', '.') }}</p>
                </button>
            @endforeach
        </div>
    @endif

    <x-jr.card>
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-semibold">Filtros</h3>
            <button class="text-sm text-primary-500" wire:click="clearFilters">Limpar filtros</button>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-jr.input label="De" type="date" wire:model.live="from" />
            <x-jr.input label="Até" type="date" wire:model.live="to" />
            <div>
                <label class="mb-2 block">Ativo</label>
                <select wire:model.live="assetFilter">
                    <option value="">Todos</option>
                    @foreach ($assets as $a)
                        <option value="{{ $a->id }}">{{ $a->ticker }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-2 block">Tipo</label>
                <select wire:model.live="typeFilter">
                    <option value="">Todos</option>
                    <option value="buy">Compra / aplicação</option>
                    <option value="sell">Venda / resgate</option>
                </select>
            </div>
        </div>
    </x-jr.card>

    <x-jr.card>
        @if (session('status'))<x-fx.alert variant="success">{{ session('status') }}</x-fx.alert>@endif

        @if ($operations->isEmpty())
            <x-fx.empty-state icon="↔" title="Nenhum registro encontrado" description="Ajuste os filtros ou registre sua primeira movimentação." />
        @else
            <div class="overflow-x-auto">
                <table class="investment-table">
                    <thead>
                        <tr>
                            <th class="text-left">Data</th>
                            <th class="text-left">Corretora</th>
                            <th class="text-left">Ativo</th>
                            <th class="text-left">Tipo</th>
                            <th class="text-right">Qtd</th>
                            <th class="text-right">Preço</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Resultado realizado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operations as $op)
                            <tr>
                                <td>{{ $op->date->format('d/m/Y') }}</td>
                                <td>{{ $op->bankAccount?->name ?? '—' }}</td>
                                <td class="font-semibold">{{ $op->asset?->ticker }}</td>
                                <td>
                                    <span class="fx-badge fx-badge--{{ $op->type === 'buy' ? 'down' : 'up' }}">
                                        {{ $op->type === 'buy' ? 'Compra' : 'Venda' }}
                                    </span>
                                </td>
                                <td class="text-right">{{ number_format((float) $op->quantity, 6, ',', '.') }}</td>
                                <td class="text-right">R$ {{ number_format((float) $op->unit_price, 4, ',', '.') }}</td>
                                <td class="text-right">R$ {{ number_format((float) $op->total, 2, ',', '.') }}</td>
                                <td class="text-right {{ ((float) $op->realized_pnl) >= 0 ? 'text-up' : 'text-down' }}">
                                    {{ $op->realized_pnl !== null ? 'R$ '.number_format((float) $op->realized_pnl, 2, ',', '.') : '—' }}
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <button class="investment-action" wire:click="edit({{ $op->id }})">Editar</button>
                                    <button class="investment-action" wire:click="delete({{ $op->id }})" wire:confirm="Excluir operação?">Excluir</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-sm">{{ $operations->links() }}</div>
        @endif
    </x-jr.card>

    @if ($showFormModal && $formStep === 'choose')
        <div class="fixed inset-0 z-modal flex items-center justify-center px-4 py-6" x-data x-on:keydown.escape.window="$wire.cancel()">
            <button type="button" class="fixed inset-0 bg-black/45" wire:click="cancel" aria-label="Fechar modal"></button>
            <div role="dialog" aria-modal="true" aria-label="Nova movimentação" class="relative w-full max-w-2xl overflow-hidden rounded-2xl border border-mono-100 bg-mono-white shadow-elevated">
                <div class="flex items-center justify-between border-b border-mono-100 px-6 py-5">
                    <h3 class="text-lg font-bold text-mono-900">Nova movimentação</h3>
                    <button type="button" wire:click="cancel" class="text-mono-600" aria-label="Fechar"><span class="material-icons-outlined">close</span></button>
                </div>
                <div class="p-6">
                    <p class="mb-5 text-sm text-mono-600">O que você deseja registrar?</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <button type="button" wire:click="chooseType('buy')" class="rounded-2xl border border-mono-100 p-5 text-left transition-colors hover:border-primary-500 hover:bg-primary-100">
                            <span class="material-icons-outlined text-primary-500">add_circle</span>
                            <strong class="mt-3 block text-base">Compra / aplicação</strong>
                            <span class="mt-1 block text-sm text-mono-600">Comprar um ativo pela conta da corretora.</span>
                        </button>
                        <button type="button" wire:click="chooseType('sell')" class="rounded-2xl border border-mono-100 p-5 text-left transition-colors hover:border-primary-500 hover:bg-primary-100">
                            <span class="material-icons-outlined text-up">remove_circle</span>
                            <strong class="mt-3 block text-base">Venda / resgate</strong>
                            <span class="mt-1 block text-sm text-mono-600">Vender somente o que você já tem na carteira.</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showFormModal && $formStep === 'form')
        <x-investments.modal :title="$editingId ? 'Editar movimentação' : ($opType === 'sell' ? 'Nova venda' : 'Nova compra')">
            @if ($errors->any())
                <div class="md:col-span-2">
                    <x-jr.alert variant="error">
                        <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                    </x-jr.alert>
                </div>
            @endif

            @unless ($editingId)
                <div class="md:col-span-2">
                    <button type="button" class="text-sm font-semibold text-primary-500" wire:click="backToType">← Alterar para {{ $opType === 'buy' ? 'venda' : 'compra' }}</button>
                </div>
            @endunless

            @if ($accounts->isEmpty())
                <div class="md:col-span-2 rounded-2xl bg-primary-100 p-4 text-sm text-mono-900">
                    Cadastre uma conta do tipo Investimento (XP, BTG...) no Financeiro para lançar a movimentação.
                    <a href="{{ route('banking.accounts.index') }}" class="ml-1 font-semibold text-primary-500">Ir para contas</a>
                </div>
            @endif

            <div>
                <label class="mb-2 block">Corretora *</label>
                <select wire:model="bank_account_id" required>
                    <option value="">— selecionar conta de investimento —</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}{{ $a->bank ? ' · '.$a->bank : '' }}</option>
                    @endforeach
                </select>
            </div>

            @if ($editingId)
                <div>
                    <label class="mb-2 block">Tipo</label>
                    <select wire:model.live="opType">
                        <option value="buy">Compra</option>
                        <option value="sell">Venda</option>
                    </select>
                </div>
            @else
                <div class="flex items-end">
                    <span class="fx-badge fx-badge--{{ $opType === 'buy' ? 'down' : 'up' }}">{{ $opType === 'buy' ? 'Compra / aplicação' : 'Venda / resgate' }}</span>
                </div>
            @endif

            @if ($opType === 'buy')
                <div>
                    <label class="mb-2 block">Ativo *</label>
                    <select wire:model.live="asset_class_id">
                        <option value="">Tipo de ativo</option>
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <x-jr.input
                label="Código / ticker *"
                name="ticker"
                icon="tag"
                wire:model.blur="ticker"
                placeholder="{{ $opType === 'sell' ? 'Código de um ativo da carteira' : 'PETR4, CDB-BANCO-2027...' }}"
                :helper="filled($lookupStatus) ? $lookupStatus : ($opType === 'sell' ? 'Pesquise pelo código ou escolha um ativo da carteira abaixo.' : 'Tickers da B3 preenchem nome, setor e preço. Você pode ajustar. Para CDB, Tesouro e cripto, selecione o tipo de ativo.')"
                :success="filled($lookupStatus)"
                required
            />

            @if ($opType === 'buy')
                <x-jr.input
                    label="Nome *"
                    name="assetName"
                    icon="edit_note"
                    wire:model="assetName"
                    placeholder="Nome do ativo"
                    required
                />
                <x-jr.input
                    label="Setor"
                    name="assetSector"
                    icon="category"
                    wire:model="assetSector"
                    placeholder="Petróleo, Financeiro..."
                />
            @endif

            <x-jr.input label="Data" type="date" name="opDate" icon="event" wire:model="opDate" />

            @if ($opType === 'sell')
                <div class="md:col-span-2">
                    <p class="mb-2 text-sm font-medium text-mono-600">Ativos na carteira</p>
                    @if ($holdings->isEmpty())
                        <p class="rounded-2xl bg-mono-50 px-4 py-3 text-sm text-mono-600">{{ $ticker !== '' ? 'Nenhum ativo da carteira corresponde a esse código.' : 'Você não possui ativos em carteira para vender. Registre uma compra primeiro.' }}</p>
                    @else
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($holdings as $holding)
                                <button type="button" wire:click="selectHolding({{ $holding->asset_id }})" @class(['rounded-2xl border px-4 py-3 text-left transition-colors', 'border-primary-500 bg-primary-100' => (int) $asset_id === (int) $holding->asset_id, 'border-mono-100 hover:border-primary-500' => (int) $asset_id !== (int) $holding->asset_id])>
                                    <strong class="block">{{ $holding->asset?->ticker }}</strong>
                                    <span class="mt-1 block text-xs text-mono-600">{{ $holding->asset?->name }} · {{ number_format((float) $holding->quantity, 6, ',', '.') }} un · médio R$ {{ number_format((float) $holding->average_price, 4, ',', '.') }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4 md:col-span-2">
                <x-jr.input label="Quantidade" type="number" step="0.000001" name="quantity" icon="numbers" wire:model.live.debounce.300ms="quantity" />
                <x-jr.input
                    label="Preço unitário"
                    type="number"
                    step="0.0001"
                    name="unit_price"
                    icon="payments"
                    wire:model.live.debounce.300ms="unit_price"
                    :helper="$priceFromMarket ? 'Cotação atual da B3. Ajuste se a sua compra foi a outro preço.' : 'Informe o preço da sua compra. Tickers da B3 preenchem automaticamente.'"
                    :success="$priceFromMarket"
                />
            </div>
            <x-jr.input label="Taxas/corretagem" type="text" x-money name="fees" icon="edit_note" wire:model.live.debounce.300ms="fees" />
            <div class="md:col-span-2">
                <label class="mb-2 block">Observações</label>
                <textarea wire:model="opNotes" rows="2"></textarea>
            </div>
            <div class="md:col-span-2 flex items-center justify-between border-t border-mono-100 pt-4">
                <span class="font-medium">Total da movimentação</span>
                <strong class="text-xl">R$ {{ number_format(max(0, (float) $quantity * (float) $unit_price + ($opType === 'buy' ? (float) $fees : -(float) $fees)), 2, ',', '.') }}</strong>
            </div>
            <div class="md:col-span-2 rounded-2xl bg-primary-100 p-4 text-sm text-mono-900">
                A movimentação é liquidada na corretora escolhida e lançada no Financeiro. Para aplicações controladas pelo valor total, utilize quantidade 1.
            </div>
        </x-investments.modal>
    @endif
</div>
