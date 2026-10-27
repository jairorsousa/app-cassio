@props(['categories', 'disabled' => false, 'model' => 'formCategoryId', 'id' => 'transaction-category', 'label' => 'Categoria', 'selectedId' => null, 'placeholder' => 'Sem categoria', 'clearValue' => null])

@php
    $icons = [
        'Utensils' => 'restaurant', 'Repeat' => 'currency_exchange', 'GraduationCap' => 'school',
        'Handshake' => 'payments', 'Receipt' => 'receipt_long', 'Gamepad2' => 'sports_esports',
        'Home' => 'home', 'HeartPulse' => 'local_hospital', 'Car' => 'directions_car',
        'Shirt' => 'checkroom', 'PawPrint' => 'pets', 'Briefcase' => 'work',
        'TrendingUp' => 'trending_up', 'Wallet' => 'payments', 'Gift' => 'account_balance_wallet', 'Tag' => 'label',
    ];
    $available = $categories->keyBy('id');
    $children = $categories->groupBy('parent_id');
    $options = [];
    $visited = [];
    $append = function ($category, $parentPath = '') use (&$append, &$options, &$visited, $children, $icons) {
        if (isset($visited[$category->id])) {
            return;
        }
        $visited[$category->id] = true;
        $path = $parentPath ? $parentPath.' / '.$category->name : $category->name;
        $options[] = [
            'id' => $category->id,
            'name' => $category->name,
            'path' => $path,
            'label' => $path,
            'description' => $parentPath,
            'indent' => $parentPath !== '',
            'parent' => $parentPath,
            'icon' => $icons[$category->icon] ?? ($category->icon ?: 'label'),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $category->color ?? '') ? $category->color : '#ff6f00',
            'child' => $parentPath !== '',
        ];
        foreach ($children->get($category->id, collect()) as $child) {
            $append($child, $path);
        }
    };
    foreach ($categories as $category) {
        if ($category->parent_id === null || ! $available->has($category->parent_id)) {
            $append($category);
        }
    }
    // Mantém as opções acessíveis mesmo se houver um vínculo circular no cadastro.
    foreach ($categories as $category) {
        $append($category);
    }
@endphp

<x-jr.searchable-select :options="collect($options)" :selected-id="$selectedId" :model="$model" :id="$id" :label="$label"
    :placeholder="$placeholder" :clear-value="$clearValue" :disabled="$disabled" placeholder-icon="label_off"
    search-label="Pesquisar categoria" search-placeholder="Digite o nome da categoria..." empty-message="Nenhuma categoria encontrada." />
