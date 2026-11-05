@props([
    'accounts',
    'selectedId' => null,
    'model',
    'id',
    'label' => 'Conta bancária',
    'placeholder' => 'Selecione',
    'clearValue' => null,
    'showTypeFilter' => true,
])

@php
    $accounts = collect($accounts);
    $options = \App\Domains\Banking\Models\BankAccount::pickerOptions($accounts);
    $typeFilters = $showTypeFilter
        ? collect(\App\Domains\Banking\Models\BankAccount::TYPE_ORDER)
            ->filter(fn ($type) => $accounts->contains(fn ($account) => $account->type === $type))
            ->map(fn ($type) => [
                'value' => $type,
                'label' => \App\Domains\Banking\Models\BankAccount::TYPE_LABELS[$type] ?? $type,
            ])
            ->values()
        : collect();
@endphp

<x-jr.searchable-select
    :options="$options"
    :selected-id="$selectedId"
    :model="$model"
    :id="$id"
    :label="$label"
    :placeholder="$placeholder"
    :clear-value="$clearValue"
    :type-filters="$typeFilters->count() > 1 ? $typeFilters : null"
    search-label="Pesquisar conta"
    search-placeholder="Digite o nome ou tipo da conta..."
    empty-message="Nenhuma conta encontrada."
/>
