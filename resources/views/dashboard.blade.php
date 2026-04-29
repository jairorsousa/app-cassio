<x-app-layout>
    <x-slot name="header">{{ __('Dashboard') }}</x-slot>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-lg">
        <x-fx.card title="Patrimônio total">
            <div class="text-xxl font-bold text-mono-900">R$ 0,00</div>
            <div class="mt-xs flex items-center gap-xs">
                <x-fx.badge variant="up">▲ 0,00%</x-fx.badge>
                <span class="text-xxs text-mono-600">vs. mês anterior</span>
            </div>
        </x-fx.card>

        <x-fx.card title="Resultado do mês">
            <div class="text-xxl font-bold text-mono-900">R$ 0,00</div>
            <div class="mt-xs text-xxs text-mono-600">Receitas − despesas</div>
        </x-fx.card>

        <x-fx.card title="Faturas a vencer (30d)">
            <div class="text-xxl font-bold text-mono-900">R$ 0,00</div>
            <div class="mt-xs text-xxs text-mono-600">Nenhuma fatura cadastrada</div>
        </x-fx.card>
    </div>

    <div class="mt-lg flex flex-wrap gap-xs">
        <x-fx.button variant="primary">+ Nova receita</x-fx.button>
        <x-fx.button variant="standard">+ Nova despesa</x-fx.button>
        <x-fx.button variant="mono">Transferência</x-fx.button>
        <x-fx.button variant="text">Novo requisitório</x-fx.button>
    </div>

    <div class="mt-lg">
        <x-fx.alert variant="info" dismissible>
            Bem-vindo ao Cassio Finance. Comece cadastrando sua primeira conta bancária no módulo Financeiro.
        </x-fx.alert>
    </div>
</x-app-layout>
