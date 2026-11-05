# Registro de alterações

## 05/11/2026 — Etapa 1: limpeza e base do Financeiro (Auditor Fin)

Base: commit `d828277` do Jairo (caixa unificado, resultado em regime de caixa, atalho do lançamento no modal). Nada foi excluído do banco.

### Mudanças
- **Saldo inicial com data** (`bank_accounts.initial_balance_date`, opcional). Saldo = saldo inicial + lançamentos liquidados com data ≥ data do saldo inicial. Sem data, o comportamento é o anterior.
- **Conferir saldo** (Contas → "Conferir saldo" ou "Conferir" na linha): informa o saldo do extrato numa data e o sistema mostra o saldo calculado e a diferença.
- **Lançamentos históricos** (derivado: data anterior ao saldo inicial da conta). Não alteram saldo, resultado do mês nem fluxo de caixa; continuam nos módulos de origem (requisitórios, corretores, valores a receber). Aviso no formulário, etiqueta "Histórico" e filtro (mostrar/ocultar/somente) na lista.
- **Dados de teste** (`is_test` em transações, contatos e valores a receber). Ficam fora de listas, totais, saldos e relatórios; filtro "Mostrar testes" em Lançamentos, Contatos e Valores a receber (nos valores a receber, os testes nunca entram nos totais). Marcados: lançamentos com a palavra "teste" na descrição (inclusive os já excluídos), contatos JAIRO DOIS, JAIRO RODRIGUES e TESTE TESTE, e o valor a receber "teste" de JAIRO RODRIGUES.
- **Unificação**: a conta "Repasse cliente" (id 11) não tinha lançamentos; vínculos (se houver) migram para "Cliente" (id 13) e a conta 11 foi desativada, não excluída.
- **Transferências**: categoria de sistema "Transferência entre contas" (tipo `transfer`, não editável nem excluível), atribuída automaticamente a toda transferência; a migration aplicou às existentes sem categoria. Transferências continuam fora de receita, despesa e resultado.
- **Categoria obrigatória** em Receita e Despesa no modal de lançamento (validação no servidor). Exceções: rateio (categoria por linha), recebimento de processo com divisão e estorno de cartão. Lançamentos gerados por outros módulos e importação OFX não foram bloqueados.
- **Investimento ↔ corrente**: conta de investimento pode ter uma conta corrente vinculada (`linked_account_id`). Aplicação e resgate = transferência; rendimento = Receita na categoria existente "Rendimento de Investimentos" (id 15) ou subcategorias.
- **Edição de lançamento** abre o mesmo modal do lançamento novo (`/banking/transactions/{id}/edit` redireciona para `?editar={id}`). Formulário legado `transactions/form.blade.php` removido.
- **Regime Caixa/Competência**: seletor persistente por usuário (`users.financial_regime`, padrão Caixa) no Dashboard, no Financeiro e no Fluxo de caixa. Caixa: compra no cartão entra pelo pagamento da fatura. Competência: compra no cartão entra pela data da compra e o pagamento da fatura não conta. Caches separados por regime.
- **Nomes das contas padronizados** (autorizado pelo Cassio): Banco do Brasil_CC → Banco do Brasil; Banco Itaú → Itaú; CEF_CC → CEF; Banco Bradesco_CC → Bradesco; Banco BTG_CC → BTG; Banco Xp → XP; Banco do Brasil_FI → BB Fundo (aplicação automática); Banco do Brasil_CI → BB Conta investimento. IDs e vínculos não mudam.
- `ExampleTest` ajustado: a raiz redireciona para o login (falhava antes desta etapa).

### Migrations
- `2026_11_05_100000_etapa1_financeiro_estrutura` — colunas novas (estrutura).
- `2026_11_05_100100_etapa1_financeiro_dados` — categoria de sistema, marcação de testes, unificação 11 → 13.
- `2026_11_05_100200_etapa1_padroniza_nomes_contas` — nomes das contas.
Backup lógico antes da migração: `/root/app-cassio-backups/`.

### Testes
`tests/Feature/Banking/Etapa1BaseFinanceiroTest.php`: saldo com data, histórico fora do saldo e do resultado, transferência com categoria de sistema e fora do resultado, categoria de sistema protegida, regimes Caixa/Competência, Dashboard = Financeiro nos dois regimes, testes fora dos totais, categoria obrigatória, edição no modal e Conferir saldo. Testes existentes de lançamento atualizados para informar a categoria.

### Duplicidades (só listadas, não marcadas)
- 2x Triconta R$ 500,00 em 05/10/2026
- 2x POSTO SANTA TEREZA R$ 150,17 em 05/10/2026
- 2 pares de transferência Rende Fácil R$ 159,21 em 15/10/2026

### Pendências do Cassio
1. Informar data de corte e saldo do extrato de cada conta (preencher "Data do saldo inicial" e conferir em "Conferir saldo").
2. Confirmar se as duplicidades acima são reais ou devem ser marcadas como teste/excluídas.
3. Confirmar a exclusão definitiva dos registros de teste (hoje só ocultos).
4. Vincular "BB Fundo (aplicação automática)" e "BB Conta investimento" à conta "Banco do Brasil" no cadastro de contas.
5. Categorizar receitas e despesas antigas sem categoria (a obrigatoriedade vale para novos lançamentos e edições).
