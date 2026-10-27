@props(['contacts', 'selectedId' => null, 'model', 'id', 'label' => 'Pessoa', 'placeholder' => 'Selecione', 'clearValue' => null])

<x-jr.searchable-select
    :options="$contacts->map(fn ($contact) => ['id' => $contact->id, 'label' => $contact->name])"
    :selected-id="$selectedId"
    :model="$model"
    :id="$id"
    :label="$label"
    :placeholder="$placeholder"
    :clear-value="$clearValue"
    search-placeholder="Digite o nome para pesquisar..."
    empty-message="Nenhuma pessoa encontrada."
/>
