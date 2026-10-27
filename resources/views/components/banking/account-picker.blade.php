@props(['accounts', 'selectedId' => null, 'model', 'id', 'label' => 'Conta bancária', 'placeholder' => 'Selecione', 'clearValue' => null])

<x-jr.searchable-select :options="$accounts->map(fn ($account) => ['id' => $account->id, 'label' => $account->name])"
    :selected-id="$selectedId" :model="$model" :id="$id" :label="$label" :placeholder="$placeholder" :clear-value="$clearValue"
    search-label="Pesquisar conta" search-placeholder="Digite o nome da conta..." empty-message="Nenhuma conta encontrada." />
