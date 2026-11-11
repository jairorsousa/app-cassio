<?php

namespace App\Support;

use App\Models\User;

/** Preferências visuais por usuário (lista fechada; valores fora da lista são rejeitados). */
class UserPreferences
{
    public const DEFAULTS = ['theme' => 'light', 'accent' => 'laranja', 'text_size' => 'padrao', 'density' => 'confortavel', 'home' => 'dashboard'];

    public const THEMES = ['light' => 'Claro', 'dark' => 'Escuro', 'auto' => 'Automático (segue o sistema)'];

    public const TEXT_SIZES = ['padrao' => 'Padrão', 'grande' => 'Grande', 'extra' => 'Extra grande'];

    public const DENSITIES = ['confortavel' => 'Confortável', 'compacta' => 'Compacta'];

    public const HOMES = ['dashboard' => ['Painel', 'dashboard'], 'financeiro' => ['Financeiro (lançamentos)', 'banking.transactions.index'], 'investimentos' => ['Investimentos', 'investments.dashboard']];

    /** [rótulo, claro [100, 500, 600], escuro [100, 500, 600]] */
    public const ACCENTS = [
        'laranja' => ['Laranja (padrão)', ['#fff0e0', '#ff6f00', '#e56300'], ['#3d2800', '#ff8c33', '#ff6f00']],
        'azul' => ['Azul', ['#e8f0fe', '#1a73e8', '#1557b0'], ['#16263f', '#5b9cf5', '#3b82f6']],
        'verde' => ['Verde', ['#e6f6ee', '#11875a', '#0d6e49'], ['#132e22', '#34c98a', '#22b072']],
        'roxo' => ['Roxo', ['#f1ebfe', '#7c3aed', '#6527c9'], ['#2a1f45', '#a78bfa', '#8b5cf6']],
        'rosa' => ['Rosa', ['#fde8f1', '#d6336c', '#b3245a'], ['#3d1a29', '#f06595', '#e64980']],
        'petroleo' => ['Petróleo', ['#e3f4f4', '#0e7c86', '#0a646c'], ['#123033', '#2bb3be', '#1c9aa4']],
    ];

    /** Itens por página nas listas (Financeiro › Lançamentos): só estes valores. */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const PER_PAGE_DEFAULT = 25;

    public static function perPage(?User $user, string $list = 'transactions'): int
    {
        $v = (int) (((array) ($user?->preferences ?? []))['per_page'][$list] ?? 0);

        return in_array($v, self::PER_PAGE_OPTIONS, true) ? $v : self::PER_PAGE_DEFAULT;
    }

    /** Ordenação da lista de Lançamentos: coluna => rótulo. Padrão: data mais nova em cima. */
    public const SORT_COLUMNS = ['date' => 'Data', 'description' => 'Descrição', 'category' => 'Categoria', 'account' => 'Conta', 'amount' => 'Valor'];

    public const SORT_DEFAULT = ['date', 'desc'];

    /**
     * Listas com colunas ordenáveis (fechadas). Padrão: data mais recente primeiro, quando houver data;
     * senão, a ordem natural da tela (nome/ticker crescente, parcela crescente).
     *
     * @var array<string, array{columns: array<string, string>, default: array{0: string, 1: string}}>
     */
    public const SORT_LISTS = [
        'transactions' => ['columns' => self::SORT_COLUMNS, 'default' => self::SORT_DEFAULT],
        'broker_statement' => ['columns' => ['date' => 'Data', 'movement' => 'Movimento', 'description' => 'Descrição', 'amount' => 'Valor', 'balance' => 'Saldo'], 'default' => self::SORT_DEFAULT],
        'broker_advances' => ['columns' => ['date' => 'Data', 'amount' => 'Valor', 'payment_method' => 'Forma pgto', 'account' => 'Conta', 'settled' => 'Compensado', 'balance' => 'Saldo', 'notes' => 'Notas'], 'default' => self::SORT_DEFAULT],
        'broker_commissions' => ['columns' => ['date' => 'Data ref.', 'case_type' => 'Tipo de caso', 'name' => 'Nome', 'amount' => 'Comissão', 'settled' => 'Compensado', 'repassed' => 'Repassado', 'balance' => 'Saldo', 'status' => 'Status'], 'default' => self::SORT_DEFAULT],
        'broker_payments' => ['columns' => ['date' => 'Data', 'case_type' => 'Tipo de caso', 'name' => 'Nome', 'account' => 'Conta', 'amount' => 'Valor', 'notes' => 'Notas'], 'default' => self::SORT_DEFAULT],
        'broker_process_expenses' => ['columns' => ['date' => 'Data', 'description' => 'Descrição', 'account' => 'Conta', 'amount' => 'Valor', 'status' => 'Situação', 'balance' => 'Saldo'], 'default' => self::SORT_DEFAULT],
        'brokers' => ['columns' => ['name' => 'Nome', 'document' => 'Documento', 'phone' => 'Telefone', 'advance_balance' => 'Saldo Adiantamento', 'commission_balance' => 'Saldo Corretor', 'status' => 'Status'], 'default' => ['name', 'asc']],
        'broker_commissions_index' => ['columns' => ['date' => 'Data ref.', 'case_type' => 'Tipo de caso', 'name' => 'Nome', 'base' => 'Base', 'percent' => '%', 'amount' => 'Comissão', 'settled' => 'Compensado', 'balance' => 'A pagar', 'status' => 'Status'], 'default' => self::SORT_DEFAULT],
        'broker_advances_index' => ['columns' => ['date' => 'Data', 'amount' => 'Valor', 'payment_method' => 'Forma pgto', 'account' => 'Conta', 'settled' => 'Compensado', 'balance' => 'Saldo', 'notes' => 'Notas'], 'default' => self::SORT_DEFAULT],
        'case_types' => ['columns' => ['name' => 'Nome', 'status' => 'Status'], 'default' => ['name', 'asc']],
        'broker_report_brokers' => ['columns' => ['broker' => 'Corretor', 'quantity' => 'Qtd', 'total' => 'Total'], 'default' => ['broker', 'asc']],
        'broker_report_commissions' => ['columns' => ['date' => 'Data', 'broker' => 'Corretor', 'case_type' => 'Tipo de caso', 'name' => 'Nome', 'amount' => 'Comissão'], 'default' => self::SORT_DEFAULT],
        'broker_report_totals' => ['columns' => ['broker' => 'Corretor', 'quantity' => 'Comissões (Qtd)', 'total' => 'Valor Total'], 'default' => ['broker', 'asc']],
        'accounts' => ['columns' => ['name' => 'Nome', 'bank' => 'Banco', 'type' => 'Tipo', 'balance' => 'Saldo'], 'default' => ['name', 'asc']],
        'cards' => ['columns' => ['name' => 'Cartão', 'holder' => 'Titular', 'limit' => 'Limite', 'cycle' => 'Fech./Venc.', 'due' => 'A pagar'], 'default' => ['name', 'asc']],
        'loans' => ['columns' => ['date' => 'Data', 'person' => 'Pessoa', 'description' => 'Descrição', 'principal' => 'Principal', 'balance' => 'Saldo', 'status' => 'Situação'], 'default' => self::SORT_DEFAULT],
        'loan_payments' => ['columns' => ['date' => 'Data', 'total' => 'Total', 'principal' => 'Principal', 'interest' => 'Juros', 'account' => 'Conta'], 'default' => self::SORT_DEFAULT],
        'loan_installments' => ['columns' => ['number' => 'Parcela', 'amount' => 'Valor', 'open' => 'Principal em aberto', 'due' => 'Vencimento'], 'default' => ['number', 'asc']],
        'receivables' => ['columns' => ['person' => 'Pessoa / descrição', 'original' => 'Valor original', 'paid' => 'Baixado', 'balance' => 'Saldo', 'due' => 'Próximo vencimento', 'status' => 'Situação'], 'default' => ['due', 'asc']],
        'receivable_installments' => ['columns' => ['number' => 'Parcela', 'amount' => 'Valor', 'balance' => 'Saldo', 'due' => 'Vencimento combinado', 'invoice' => 'Fatura do cartão'], 'default' => ['number', 'asc']],
        'recurring' => ['columns' => ['description' => 'Descrição', 'frequency' => 'Frequência', 'amount' => 'Valor', 'status' => 'Status'], 'default' => ['description', 'asc']],
        'cashflow_items' => ['columns' => ['date' => 'Data', 'source' => 'Origem', 'description' => 'Descrição', 'inflow' => 'Entrada', 'outflow' => 'Saída'], 'default' => ['date', 'asc']],
        'cashflow_months' => ['columns' => ['month' => 'Mês', 'income' => 'Entradas previstas', 'expense' => 'Saídas previstas', 'net' => 'Líquido', 'balance' => 'Saldo projetado'], 'default' => ['month', 'asc']],
        'cashflow_categories' => ['columns' => ['category' => 'Categoria', 'own_income' => 'Receita própria', 'own_expense' => 'Despesa própria', 'other_in' => 'Outras entradas', 'other_out' => 'Outras saídas', 'result' => 'Resultado próprio'], 'default' => ['category', 'asc']],
        'cashflow_accounts' => ['columns' => ['account' => 'Conta', 'inflow' => 'Entradas', 'outflow' => 'Saídas', 'balance' => 'Saldo do período'], 'default' => ['account', 'asc']],
        'contacts' => ['columns' => ['name' => 'Nome', 'type' => 'Tipo', 'document' => 'Documento', 'phone' => 'Telefone', 'email' => 'E-mail', 'status' => 'Status'], 'default' => ['name', 'asc']],
        'division_movements' => ['columns' => ['date' => 'Data', 'type' => 'Tipo', 'description' => 'Descrição', 'amount' => 'Valor'], 'default' => self::SORT_DEFAULT],
        'properties' => ['columns' => ['name' => 'Bem', 'type' => 'Tipo', 'holder' => 'Titular', 'acquisition' => 'Aquisição / total', 'current' => 'Valor atual', 'status' => 'Situação'], 'default' => ['name', 'asc']],
        'assets' => ['columns' => ['ticker' => 'Ticker', 'name' => 'Nome', 'class' => 'Classe', 'quantity' => 'Quantidade', 'avg_price' => 'Preço médio'], 'default' => ['ticker', 'asc']],
        'operations' => ['columns' => ['date' => 'Data', 'broker' => 'Corretora', 'asset' => 'Ativo', 'type' => 'Tipo', 'quantity' => 'Qtd', 'price' => 'Preço', 'total' => 'Total', 'result' => 'Resultado realizado'], 'default' => self::SORT_DEFAULT],
        'dividends' => ['columns' => ['date' => 'Data', 'asset' => 'Ativo', 'type' => 'Tipo', 'quantity' => 'Qtd', 'per_share' => 'R$/un', 'total' => 'Total'], 'default' => self::SORT_DEFAULT],
        'positions' => ['columns' => ['ticker' => 'Ticker', 'class' => 'Classe', 'quantity' => 'Quantidade', 'avg_price' => 'Preço médio', 'invested' => 'Investido', 'quote' => 'Cotação', 'market' => 'Valor de mercado', 'result' => 'Resultado', 'percent' => '%'], 'default' => ['ticker', 'asc']],
        'profitability' => ['columns' => ['asset' => 'Ativo', 'purchases' => 'Compras', 'sales' => 'Vendas', 'gain' => 'Lucro / prejuízo nas vendas', 'income' => 'Proventos', 'result' => 'Resultado'], 'default' => ['asset', 'asc']],
        'b3_positions' => ['columns' => ['asset' => 'Ativo', 'status' => 'Situação', 'quantity' => 'Quantidade', 'custody' => 'Custódia', 'lent' => 'Emprestada', 'avg_price' => 'PM', 'close' => 'Fechamento', 'value' => 'Valor', 'institutions' => 'Instituições (B3)'], 'default' => ['asset', 'asc']],
        'b3_years' => ['columns' => ['year' => 'Ano', 'total' => 'Total'], 'default' => ['year', 'desc']],
        'b3_incomes' => ['columns' => ['date' => 'Pagamento', 'asset' => 'Ativo', 'type' => 'Tipo', 'quantity' => 'Qtd. base', 'gross' => 'Bruto', 'tax' => 'IR', 'net' => 'Líquido', 'broker' => 'Corretora', 'source' => 'Origem'], 'default' => self::SORT_DEFAULT],
        'statement_rows' => ['columns' => ['number' => '#', 'date' => 'Data', 'ticker' => 'Ticker', 'type' => 'Tipo', 'quantity' => 'Qtd', 'gross' => 'Bruto', 'tax' => 'IR', 'net' => 'Líquido', 'status' => 'Status'], 'default' => ['number', 'asc']],
        'statement_events' => ['columns' => ['date' => 'Data', 'ticker' => 'Ticker', 'description' => 'Descrição', 'type' => 'Tipo', 'amount' => 'Valor', 'status' => 'Situação'], 'default' => self::SORT_DEFAULT],
        'writs_report_open' => ['columns' => ['beneficiary' => 'Beneficiário', 'process' => 'Processo', 'type' => 'Tipo', 'purchase' => 'Compra', 'forecast' => 'Previsão', 'status' => 'Situação'], 'default' => ['forecast', 'asc']],
        'writs_report_groups' => ['columns' => ['group' => 'Grupo', 'quantity' => 'Qtde', 'real_avg' => 'Média real', 'range' => 'Mín–máx', 'estimated_avg' => 'Média estimada', 'diff' => 'Diferença'], 'default' => ['group', 'asc']],
        'writs_report_times' => ['columns' => ['process' => 'Processo', 'type' => 'Tipo', 'court' => 'Tribunal', 'purchase' => 'Compra', 'receipt' => 'Recebimento', 'real' => 'Real', 'estimated' => 'Estimado'], 'default' => ['receipt', 'desc']],
        'writs_report_profit' => ['columns' => ['process' => 'Processo', 'type' => 'Tipo', 'paid' => 'Pago', 'received' => 'Recebido', 'profit' => 'Lucro', 'percent' => '%', 'days' => 'Dias', 'monthly' => '% a.m.'], 'default' => ['profit', 'desc']],
        'writs_timeline' => ['columns' => ['date' => 'Data', 'type' => 'Tipo', 'description' => 'Descrição', 'amount' => 'Valor'], 'default' => self::SORT_DEFAULT],
        'writs_metrics_months' => ['columns' => ['month' => 'Mês', 'received' => 'Recebido', 'profit' => 'Lucro', 'quantity' => 'Qtde'], 'default' => ['month', 'asc']],
        'writs_metrics_years' => ['columns' => ['year' => 'Ano', 'received' => 'Recebido', 'cost' => 'Custo', 'profit' => 'Lucro', 'percent' => '%', 'quantity' => 'Qtde'], 'default' => ['year', 'desc']],
        'writs_metrics_forecast' => ['columns' => ['month' => 'Mês previsto', 'expected' => 'Deve voltar', 'cost' => 'Custo', 'quantity' => 'Qtde'], 'default' => ['month', 'asc']],
        'partnership_contributions' => ['columns' => ['date' => 'Data', 'status' => 'Status', 'purpose' => 'Finalidade', 'account' => 'Conta', 'amount' => 'Valor'], 'default' => self::SORT_DEFAULT],
        'partnership_expenses' => ['columns' => ['date' => 'Data', 'description' => 'Descrição', 'category' => 'Categoria', 'account' => 'Conta', 'total' => 'Total', 'share' => 'Participação', 'amount' => 'Valor do sócio'], 'default' => self::SORT_DEFAULT],
        'partnership_distributions' => ['columns' => ['date' => 'Data', 'source' => 'Origem', 'account' => 'Conta de destino', 'amount' => 'Valor recebido'], 'default' => self::SORT_DEFAULT],
        'import_preview' => ['columns' => ['date' => 'Data', 'description' => 'Descrição', 'category' => 'Categoria', 'amount' => 'Valor'], 'default' => ['date', 'asc']],
    ];

    /** @return array<string, string> */
    public static function sortColumns(string $list = 'transactions'): array
    {
        return self::SORT_LISTS[$list]['columns'] ?? [];
    }

    /** @return array{0: string, 1: string} */
    public static function sortDefault(string $list = 'transactions'): array
    {
        return self::SORT_LISTS[$list]['default'] ?? self::SORT_DEFAULT;
    }

    /** @return array{0: string, 1: string} [coluna, direção] validados (lista fechada; inválido volta ao padrão). */
    public static function sort(?User $user, string $list = 'transactions'): array
    {
        $v = (array) (((array) ($user?->preferences ?? []))['sort'][$list] ?? []);

        return self::validSort((string) ($v['by'] ?? ''), (string) ($v['dir'] ?? ''), $list);
    }

    /** @return array{0: string, 1: string} */
    public static function validSort(string $by, string $dir, string $list = 'transactions'): array
    {
        $columns = self::sortColumns($list);
        if ($columns === [] || ! array_key_exists($by, $columns)) {
            return self::sortDefault($list);
        }

        return [$by, in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc'];
    }

    /** @return array{0: string, 1: string} */
    public static function setSort(?User $user, string $by, string $dir, string $list = 'transactions'): array
    {
        [$by, $dir] = self::validSort($by, $dir, $list);
        if ($user) {
            $prefs = (array) ($user->fresh()?->preferences ?? $user->preferences ?? []);
            $prefs['sort'] = array_merge((array) ($prefs['sort'] ?? []), [$list => ['by' => $by, 'dir' => $dir]]);
            $user->forceFill(['preferences' => $prefs])->saveQuietly();
        }

        return [$by, $dir];
    }

    public static function setPerPage(?User $user, int $value, string $list = 'transactions'): int
    {
        $value = in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : self::PER_PAGE_DEFAULT;
        if ($user) {
            $prefs = (array) ($user->preferences ?? []);
            $prefs['per_page'] = array_merge((array) ($prefs['per_page'] ?? []), [$list => $value]);
            $user->forceFill(['preferences' => $prefs])->saveQuietly();
        }

        return $value;
    }

    public static function rules(): array
    {
        return [
            'theme' => ['required', 'in:'.implode(',', array_keys(self::THEMES))],
            'accent' => ['required', 'in:'.implode(',', array_keys(self::ACCENTS))],
            'text_size' => ['required', 'in:'.implode(',', array_keys(self::TEXT_SIZES))],
            'density' => ['required', 'in:'.implode(',', array_keys(self::DENSITIES))],
            'home' => ['required', 'in:'.implode(',', array_keys(self::HOMES))],
        ];
    }

    public static function for(?User $user): array
    {
        $saved = (array) ($user?->preferences ?? []);
        $out = self::DEFAULTS;
        foreach (self::rules() as $k => $r) {
            $allowed = explode(',', substr($r[1], 3));
            if (isset($saved[$k]) && in_array($saved[$k], $allowed, true)) {
                $out[$k] = $saved[$k];
            }
        }

        return $out;
    }

    public static function homeUrl(?User $user): string
    {
        $route = self::HOMES[self::for($user)['home']][1];
        if (! \App\Support\Access::allows($user, \App\Support\Access::forRoute($route))) {
            $route = \App\Support\Access::homeRoute($user);
        }

        return route($route, absolute: false);
    }

    /** CSS das variáveis de destaque (claro + escuro). Valores vêm só da lista acima. */
    public static function accentCss(string $accent): string
    {
        [, $l, $d] = self::ACCENTS[$accent] ?? self::ACCENTS['laranja'];

        return ':root,[data-theme="light"]{--colors-primary-g100:'.$l[0].';--colors-primary-g500:'.$l[1].';--colors-primary-g600:'.$l[2].'}'
            .'[data-theme="dark"]{--colors-primary-g100:'.$d[0].';--colors-primary-g500:'.$d[1].';--colors-primary-g600:'.$d[2].';--brand-700:'.$d[2].'}';
    }
}
