# Registro de alterações

## 05/11/2026 — Etapa 6 (ajuste UI): Paguei por alguém nos modais de lançamento (Auditor Fin)

Base: commit `f769b58`. Sem migration e sem registros reais.

Pendência: o botão **Paguei por alguém** existia só na lista de lançamentos já salvos; os modais **Nova despesa** e **Nova despesa no cartão** não mostravam o atalho (Receita/Transferência corretamente sem o botão).

Correção:
1. Botão visível no rodapé dos modais de **despesa bancária** e **despesa no cartão** (novo e edição). Ausente em Receita e Transferência.
2. Novo: redireciona para Valores a receber com rascunho (`criar=1`, origem bank/card, data, valor, descrição, conta/cartão) — **não** grava o lançamento no modal; a saída nasce só ao salvar em Valores a receber.
3. Edição: redireciona com `transaction=ID` (fluxo existente, sem duplicar).
4. Teste `Etapa6PagueiPorAlguemModalTest` cobre presença/ausência nos quatro tipos e o redirecionamento.


## 05/11/2026 — Etapa 6: Valores a receber com juros e Empréstimos (Auditor Fin)

Base: commit `8719f60`. Backup lógico: `/root/app-cassio-backups/etapa6-antes-20261105.json`. Nenhum empréstimo/baixa real criado; dados antigos preservados (encargos padrão zero; baixas antigas = principal).

### A) Valores a receber (“Paguei por alguém”)
1. Botão **Paguei por alguém** no lançamento (antes “Dividir com terceiros”) abre o fluxo existente sem duplicar a saída.
2. Encargos opcionais no cadastro: juros combinados % a.m., multa %, mora % a.m., data inicial. Fórmulas em `App\Support\SimpleInterest` e `docs/valores-a-receber.md`. Alocação de baixas: multa → juros → principal.
3. Baixa **Devolução em dinheiro (espécie)** seleciona a conta Dinheiro; continua podendo escolher qualquer conta.
4. Cartão, desconto em processo e perda preservados; principal a recuperar continua fora da despesa própria. Juros/multa recebidos entram na receita; principal devolvido não.

### B) Empréstimos concedidos (módulo novo)
- Menu Financeiro → **Empréstimos** (`/banking/loans`), lista/novo/ficha/baixas/anexo privado.
- Saída = categoria de sistema **Empréstimo concedido** (`source_type` Loan), fora do resultado.
- Juros recebidos → categoria **Juros de empréstimos**; principal recebido não é receita.
- Ex.: 5000 em 5×, duas pagas ⇒ 3000. Datas com `BusinessDate` (Fortaleza).

### C) Dashboard
Etapa 7 não iniciada: nenhum card/alteração de Dashboard.

### Testes / publicação
- `tests/Feature/Banking/Etapa6ValoresReceberEmprestimosTest.php`
- `tests/Unit/SimpleInterestTest.php` (se presente)
- Suíte completa após migrate + caches.


## 05/11/2026 — Correção de fuso nas validações de data (Auditor Fin)

Base: commit `bfe7212`. Sem migration e sem alteração de dados reais.

### Causa comprovada
`config/app.php` fixa `timezone = UTC` (o `APP_TIMEZONE` do .env não é lido). Regras `before_or_equal:today` e os valores padrão de data (`now()->toDateString()`) usavam o dia UTC, enquanto `BrokerProcessExpenseService` usava `America/Sao_Paulo`. Entre 21h e 24h em Fortaleza (00h–03h UTC), o "hoje" UTC já é o dia seguinte: os testes da Etapa 3 geravam 06/11 (UTC) e o serviço só aceitava até 05/11 (local) — reproduzido às 21h55 locais (00h55 UTC de 06/11). Em produção, no mesmo horário, as telas aceitavam como "hoje" uma data que localmente é amanhã.

### Correção
- `config('app.business_timezone')` (padrão `America/Fortaleza`) e `App\Support\BusinessDate` (`now()`, `today()`). Timestamps continuam gravados em UTC (nada muda em dados existentes).
- "Não pode ser futura" passa a comparar com o dia de Fortaleza em: destinação/repasses de recebimento, Valores a receber, despesas de processo do corretor, repasses da conta, pagamento de fatura, cotação/operações/proventos/rentabilidade de Investimentos. Os padrões de data e o `max` desses formulários usam o mesmo dia.
- Data de amanhã (local) continua bloqueada — coberto por `tests/Unit/BusinessDateTest.php` (22h30 em Fortaleza = 01h30 UTC).
- Testes que montavam "hoje" em UTC (`Etapa3CorretoresContatosTest`, `ReceiptDestinationTest`, `RepassePaymentsTest`) agora usam `BusinessDate`; `InvestmentWorkspaceTest` congela o relógio ao meio-dia em vez de 00h UTC (que é 21h do dia anterior em Fortaleza). Nenhuma asserção foi removida.

### Testes
Suíte completa às 21h56 locais (00h56 UTC, janela do problema): **414 passando, 0 falhas** (2610 asserções).

## 05/11/2026 — Etapa 5: Cartões e faturas (Auditor Fin)

Base: commit `d365cd2`. Backup lógico antes da migration: `/root/app-cassio-backups/etapa5-antes-20261105.json` (8 cartões, 5 faturas, 4 compras no cartão). Nenhum pagamento real foi lançado; nenhum dado existente foi alterado.

### Mudanças
1. **Titular por cartão** (`holder_type`: Cássio, Filho(a) + nome, Luciane, Contato + vínculo). Cartões antigos ficam **Não informado** (selo amarelo) — nada é inferido do nome do cartão. Filtro por titular em Cartões e em Lançamentos (“Cartão: <titular>”, gastos por pessoa).
2. **Faturas**: status exibido Aberta / Fechada / Parcial / Paga / **Vencida** (derivada de vencimento passado + saldo). Botão **Pagar** para toda fatura com restante > 0 (antes só aparecia em Aberta/Parcial — faturas Fechadas e vencidas ficavam sem pagamento); em fatura aberta aparece **Antecipar**.
3. **Pagamento** em modal: conta (ativa), valor total ou parcial (pré-preenchido com o restante; aceita 1.234,56), data (não futura), observação; bloqueia valor acima do restante e fatura já quitada. Histórico de pagamentos e compras (com parcela n/N e categoria) ao expandir a fatura. Resumo por cartão: a pagar, vencido, em aberto.
4. **Quitação, não despesa**: pagamento continua `invoice_payment`, sai da conta; `paid_amount`/status são recalculados a partir dos pagamentos liquidados (`InvoiceService::syncPayments`). Excluir um pagamento devolve a fatura para devida; compra nova/alterada/movida de fatura recalcula as duas faturas. Regimes mantidos: Caixa = pelo pagamento; Competência = pela data da compra (pagamento ignorado) — coberto por teste.
5. **Fechamento automático** (`CloseInvoiceJob`) passa a fechar toda fatura aberta cujo fechamento já passou (antes dependia do dia exato e perdia dias sem agendador).

### Migration
- `2026_11_05_500000_etapa5_credit_card_holder` — colunas nulas `holder_type`, `holder_name`, `holder_contact_id` em `credit_cards`. Efeito em antigos: nenhum (todos “Não informado”).

### Observações de dados (não alterados)
- Nubank: faturas 2026-11 (fechada) e 2026-12 (aberta) com total R$ 500,00 e **nenhuma compra vinculada** — conferir com o Cássio antes de pagar; se uma compra for lançada/alterada nelas, o total é recalculado pelas compras.

### Testes
- Novo `tests/Feature/Banking/Etapa5CartoesFaturasTest.php` (9 casos). Suíte completa: 411 ok, 2 falhas pré-existentes em `Etapa3CorretoresContatosTest` (data “hoje” em UTC após 21h de Fortaleza; sem relação com cartões).

## 05/11/2026 — Etapa 4 (ajuste visual): Perdido, Assinatura e Fluxo de caixa (Auditor Fin)

Base: commit `50fd288`. Sem migration e sem alteração de dados reais.

### Correções
1. **Perdido (detalhe + modal do kanban)**: painel com rótulo inequívoco **Motivo da perda (obrigatório)**; a “Nota da transição (opcional)” some quando a etapa escolhida é Perdido; validação e botão desabilitado sem motivo (`:disabled` Alpine, não `@disabled` do Blade).
2. **Assinatura no formulário de transição**: campo de data/hora passa a aparecer ao selecionar Assinatura (`wire:key` no bloco, input nativo, `updatedTransitionTo`); grava `signature_at` com teste. No modal Novo Requisitório do kanban, etapa Assinatura também pede/grava a data.
3. **Fluxo de caixa**: `#[Url(history: true, keep: true)]`, `updatedMonth`/`normalizeMonth` e input de mês nativo com `wire:model.live` — a primeira troca de mês (ex.: nov → out/2026) recalcula Juros Ganho e Retorno de capital sem precisar reabrir a URL; valores não duplicam.

### Testes
`tests/Feature/Writs/Etapa4TransitionUiTest.php` (4 casos). Suíte Etapa4 completa passando.

## 05/11/2026 — Etapa 4: Requisitórios (Auditor Fin)

Base: commit `a87b662`. Backup lógico antes da migration: `/root/app-cassio-backups/etapa4-antes-20261105.json` (36 requisitórios). Nenhum registro existente foi movido de etapa nem teve valor recalculado.

### Objetivo de negócio
Funil da Teto para compra de RPV/precatórios: Monitorar → Negociação → Cessão Pendente → **Assinatura** → Pagamento → Peticionamento → Aguardando Recebimento → Recebido; **Perdido** a qualquer momento com motivo. Recebimento de crédito já comprado continua baixando como Recebido sem repasse ao cliente (regra da Etapa 2 preservada).

### Mudanças
1. **Perdido a partir de qualquer etapa operacional** (Monitorar até Recebido), sempre com motivo obrigatório, histórico em `writ_stage_history`, botão do modal desabilitado sem motivo e validação no serviço.
2. **Etapa Assinatura** (`signature` + `signature_at`): fica entre Cessão Pendente e Pagamento. Transição exige data/hora. Cards antigos em Pago/Peticionar/Aguardando/Recebido permanecem onde estão; novos fluxos passam por Assinatura.
3. **Prazos**: prazo estimado = `paid_at + estimated_months`; prazo real = dias entre pagamento e recebimento (ou hoje se ainda aberto); destaque de atraso só quando há `paid_at` e `estimated_months` — sem inventar dados para antigos incompletos. Visível no detalhe e no card do kanban.
4. **Totais do funil**: Monitorar/Negociação/Cessão/Assinatura/Perdido **não** entram como aquisição. Em aberto = só Pago + Peticionamento + Aguardando. Custo = `paid_amount + despesas` (`totalCost`). Lucro esperado/líquido sobre o **custo total**; se não houver custo, mostra “sem custo” em vez de %. Face, negociado, custo e recebimento ficam separados nos cards.
5. **Juros Ganho (RPV/Precatórios)** nos relatórios de fluxo: lucro realizado separado do retorno de capital. Recebimento direto do requisitório marca a categoria; o relatório rateia lucro × retorno. No vínculo com recebimento de processo, a entrada do requisitório continua zerada (sem duplicar caixa) e o lucro vai para Juros Ganho; regimes Caixa/Competência preservados via `OwnFinancialService`.
6. Rótulos alinhados ao funil: Monitorar, Assinatura, Pagamento, Peticionamento. Google Calendar usa os mesmos rótulos.

### Migration
- `2026_11_05_400000_etapa4_requisitorios_assinatura` — inclui `signature` no ENUM (MySQL) e coluna `signature_at`. O create de `writs` também lista `signature` para o SQLite dos testes.
- Efeito em registros antigos: **nenhum**. Só passa a aceitar a nova etapa; 0 cards em Assinatura após o deploy.

### Testes
- Novo: `tests/Feature/Writs/Etapa4RequisitoriosTest.php` (8 casos: Assinatura no fluxo, Perdido de todas as etapas, prazos, totais, Juros Ganho, vínculo processo, kanban).
- Ajustados: `WritPipelineTest`, `WritKanbanPageTest`, `GoogleCalendarServiceTest`, `ReceiptWritLinkTest` (categoria do relatório), `ConsolidatedSnapshotTest`.
- Suíte relacionada (Etapa4 + Pipeline + Kanban + Calendar + Etapa2 + ReceiptWritLink + forms calendar): passando.

### Conferência
- `view:clear` / `cache:clear` / `config:clear` / `route:clear` no container.
- Produção: stages atuais inalterados (`monitoring` 5, `paid` 2, `petitioning` 1, `awaiting_receipt` 18, `finalized` 10); coluna `signature_at` presente.

### Pendências para o Cassio confirmar
1. Conferir no computador o kanban com a coluna **Assinatura** e a nova ordem/rótulos.
2. Conferir modal de Perdido a partir de um card em Pagamento/Aguardando (motivo obrigatório).
3. Conferir prazos estimado vs real num card com `paid_at` e `estimated_months` preenchidos.
4. Conferir no Fluxo de caixa o desdobramento **Juros Ganho** × **Retorno de capital** nos meses com requisitório recebido (ex.: Antônio Sérgio / out-2026).
5. Etapa 5 (Cartões e faturas) e Dashboard ainda não iniciados.

## 05/11/2026 — Correções de UI pós-Etapa 3 (Auditor Fin)

Autorizações: menu (Cassio), modal Contatos (Cassio), comissão no lançamento de Corretores (Cassio), seletor Conta/Categoria no Financeiro.

### Causa comprovada (não era só cache do navegador)
1. **Modal Novo Contato** (`contacts/index`): a Etapa 3 atualizou só o formulário completo (`contacts/form`). O modal da listagem continuava com `Tipo` único (`type_form`) — pendência já registrada.
2. **Novo lançamento → Comissão** (`brokers/index` e `brokers/show`): o modo percentual/fixo existia em `brokers/commissions/form` + `registerFlexible`, mas o modal unificado da listagem/ficha só chamava `registerFixedAmount` e mostrava "Valor da comissão".
3. **Seletor Conta/Categoria vazio** no modal de lançamento: bug de **sintaxe JavaScript** em `resources/views/components/jr/searchable-select.blade.php` (commit `1795b8b`). Em `headerVisible()`, `querySelectorAll([data-option-search])` e `(… || )` quebravam o Alpine.js do componente. Conta e Categoria usam esse componente; os chips de tipo ainda renderizavam em HTML, mas a lista ficava inutilizável. Views compiladas + OPcache com `validate_timestamps=On` refletiam o arquivo quebrado — limpar cache sozinho não corrigia.
4. **Menu superior**: ordem/rótulos ainda não tinham sido alterados (`app-nav`).
5. Certificado `ERR_CERT_AUTHORITY_INVALID` inicial: observado no acesso do Cassio; não alteramos Cloudflare/outros serviços. App em si responde após aceitar o aviso.

### Mudanças
- Menu (`app-nav`): Dashboard → Financeiro → Investimentos → Requisitório → Contatos → Corretores → Sociedade → Transmissão.
- Modal Contatos: checkboxes de papéis (Cedente, Corretor, Advogado, Pessoal, Cliente), validações CPF/telefone alinhadas ao formulário completo, sync de perfil corretor.
- Modal Novo lançamento (Corretores): seletor Percentual/Valor fixo + base de cálculo; create via `registerFlexible` (sem duplicar lançamento).
- `searchable-select`: corrige `headerVisible` (aspas no seletor CSS e fallback `''`).
- `view:clear` no container do app após a correção.

### Testes
- `tests/Feature/Ui/Etapa3UiCorrecoesTest.php` — menu, modal contatos, JS do seletor, markup Conta/Categoria, comissão %/fixo no modal.
- `tests/Feature/Layout/MobileNavigationTest.php` — rótulos atualizados.

### Conferir na tela
1. Menu superior na ordem/rótulos acima (hard refresh se necessário).
2. Contatos → Novo Contato: Papéis com vários checkboxes, inclusive Cliente.
3. Corretores → Novo lançamento → Comissão: Modo Percentual/Valor fixo.
4. Financeiro → Novo lançamento → Despesa: Conta bancária e Categoria listam opções ao abrir o dropdown.


## 05/11/2026 — Etapa 3: Corretores e Contatos (Auditor Fin)

Base: commit `dfecaba` (fim da Etapa 2). Nenhum dado real foi apagado. Backup lógico antes: `/root/app-cassio-backups/etapa3-antes-20261105.json`.

### Contatos
- **Múltiplos papéis** por contato (`roles` JSON: Cedente, Corretor, Advogado, Pessoal, Cliente), mantendo `type` como papel principal para compatibilidade. Filtros `ofType` / `hasRole` consideram type e roles.
- **CPF obrigatório** para cedente (validação com dígitos verificadores). Telefone BR (10/11 dígitos). Dados inválidos existentes **não são apagados**; o cadastro aparece como "Cadastro incompleto".
- PIX/dados bancários em destaque quando o papel Corretor está marcado. Contato com papel corretor sincroniza o perfil financeiro (`BrokerProfileService`).

### Corretores
- Comissão avulsa com **percentual ou valor fixo** (`registerFlexible`), vínculo a processo (`process_ref`) e/ou requisitório (`writ_id`), com base e rótulo de cálculo na lista.
- **Adiantamento pessoal** vs **despesa de processo** separados: despesas na aba própria (com processo/cliente), fora do saldo pessoal; adiantamentos antigos ficam `kind=personal`; opção de **reclassificar** adiantamento pessoal → despesa de processo (sem compensações).
- Fórmulas na ficha: saldo adiantado pessoal, comissão gerada/compensada/repassada/a pagar, **saldo devedor**, despesas de processo a recuperar.
- **Extrato com saldo corrido** linha a linha (coluna Saldo); filtro por período/ano (padrão = ano corrente).
- Relatórios: ano padrão = ano atual; excluem contatos `is_test`; total anual de comissões por corretor; PDF mantido.
- **Corretores padrão no requisitório** (`writ_default_brokers`): pré-preenchem a lista no recebimento ao selecionar o requisitório.

### Seletor de conta (ajuste nesta etapa)
- Novo tipo **`fund`** (Fundo — aplicação automática), distinto de Investimento. Conta "BB Fundo (aplicação automática)" migrada de `investment` → `fund`.
- Seletor (`account-picker` / `searchable-select`): mostra **nome · tipo** (ex.: Banco do Brasil · Corrente), agrupa por tipo, filtro por chips de tipo e busca por nome/tipo — útil quando há várias contas do mesmo banco.

### Migrations
- `2026_11_05_300000_etapa3_corretores_contatos` — roles, campos de comissão/adiantamento/despesa, writ_default_brokers, defaults do corretor.
- `2026_11_05_300100_etapa3_tipo_conta_fundo` — ENUM `fund` + migração do BB Fundo.

### Testes
- `tests/Feature/Brokers/Etapa3CorretoresContatosTest.php`: papéis, validação CPF/telefone, comissão %/fixo, separação de saldos (adiantamento 1000 + comissão 200 compensada ⇒ saldo_devedor 800; despesa 300 fora), saldo corrido, ano padrão, is_test fora do relatório, reclassificação, corretores padrão no writ.
- `tests/Feature/Banking/AccountPickerTest.php`: rótulo nome·tipo, agrupamento e distinção Fundo/Investimento.

### Pendências
1. Confirmar com o Cassio os saldos das contas de investimento/fundo/Dinheiro/repasses na data de corte (só correntes foram informadas).
2. Conta "XP Investimentos" (R$ 200 mil sem data) permanece intocada.
3. Limpeza definitiva dos registros de teste — só ocultos via `is_test`.
4. ~~Modal rápido de Contatos~~ — resolvido na correção de UI abaixo.
5. Prefill de corretores no recebimento depende de selecionar o requisitório com "crédito que eu já comprei"; não há cadastro de processos separado.



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

### Saldos iniciais e vínculos (05/11/2026)
- Migration `2026_11_05_100300_etapa1_saldos_iniciais_e_vinculos` (idempotente): saldo inicial em 01/10/2026 informado pelo Cassio — Banco do Brasil 84.927,38; Itaú 68.802,72; CEF 66.888,02; Bradesco 29.885,23; BTG 14.425,81; XP 1.376,39.
- BB Fundo (aplicação automática) e BB Conta investimento vinculadas à conta Banco do Brasil.
- Backup antes: `/root/app-cassio-backups/etapa1-saldos-antes-20261105.json`. Nenhum ajuste de saldo foi lançado; diferenças com o extrato devem ser apuradas em "Conferir saldo".

## 05/11/2026 — Etapa 2: rateios e vínculos do lançamento (Auditor Fin)

Base: commit `ee6cece` (fim da Etapa 1). Não havia commits novos do Jairo. Nenhum dado existente foi alterado; a migration só cria colunas e tabelas opcionais.

### Mudanças
- **Rateio de despesa/receita**: o controle no modal passa a se chamar "Dividir este lançamento em categorias" (despesa, receita comum e despesa no cartão), mostra Total, Rateado e Restante, aceita valor ou %, mínimo de duas linhas. Se a soma não fecha, não salva e avisa "A soma das categorias deve ser igual a R$ X. Falta ratear R$ Y." (ou "Excedente"). Continua sendo um único lançamento bancário; a lista mostra "N categorias" com as partes e o botão Ratear/Editar rateio edita sem recriar.
- **Vínculos opcionais do lançamento** (`transactions.link_process`, `link_writ_id`, `link_broker_id`, `link_contact_id`): processo (número, texto — não existe cadastro de processos), requisitório, corretor e pessoa (contato), com campo de busca no modal (novo e edição). Aparecem na lista com link de filtro. Novo filtro "Vínculo" em Lançamentos (requisitório, corretor, pessoa, processo, "somente com vínculo"); o filtro de requisitório/corretor também acha recebimentos de processo que os usam. Vínculos não alteram valor, saldo nem categoria. Vínculos antigos (divisões, repasses, requisitórios) não foram tocados.
- **Recebimento de processo com 2+ corretores**: lista dinâmica (Adicionar/Remover corretor). Cada linha: corretor, base (honorários ou valor bruto), modo (percentual ou fixo), taxa/valor e valor calculado. Validação: escritório + Σ corretores ≤ honorários ("Minha receita não pode ficar negativa"). Ao salvar nasce **uma comissão por corretor** no módulo Corretores. Compensação de adiantamento por corretor, opcional, só com adiantamentos daquele corretor. Detalhe gravado em `process_receipt_brokers` (base, modo, taxa/valor, calculado, compensado, comissão). Despesas a descontar do cliente valem para qualquer corretor selecionado. Resumo ao vivo: entrada, requisitório, cliente, honorários, escritório, cada corretor, minha receita.
- **Compatibilidade**: o formato antigo (um corretor: `broker_id`, `broker_amount`, `commission_mode`) continua aceito pelo serviço e pelo formulário; divisões já salvas abrem na lista nova como valor fixo, com os mesmos valores. Nenhum valor salvo foi recalculado.
- **Recebimento vinculado a requisitório comprado**: no recebimento, "Este valor inclui crédito que eu já comprei" revela o seletor de requisitório (comprados em Pago, Peticionar ou Aguardando recebimento) e o valor. Ao salvar: o requisitório vai para **Recebido** com data = data da entrada e valor recebido real; o prazo real (dias desde o pagamento da aquisição) e o lucro aparecem no requisitório e na lista; a parte do cliente é baixada pelo mecanismo já existente de vínculo (a entrada do requisitório fica com R$ 0,00), então o dinheiro conta uma vez só e **não** vira repasse ao cliente. Grava `process_receipts.writ_id/writ_amount` e o vínculo do lançamento. Campo "Processo (número)" no recebimento (`process_receipts.process_ref`).
- **Anexo de comprovante**: PDF, JPG ou PNG até 10 MB, no modal (novo e edição). Arquivo no disco privado (`storage/app/private/comprovantes/{id}/uuid.ext`), nunca público; download só autenticado em `/banking/transactions/attachments/{id}`; remover pelo modal de edição. Tabela `transaction_attachments`.

### Migration
- `2026_11_05_200000_etapa2_vinculos_corretores_anexos` — somente estrutura, reversível. Backup lógico antes: `/root/app-cassio-backups/etapa2-antes-migration-20261105.json`.

### Testes
`tests/Feature/Banking/Etapa2RateiosVinculosTest.php`: rateio 400+200+400 salva e 400+200+300 recusa com o restante; caso âncora Antônio Sérgio (78.810,26; requisitório 55.167,18 Recebido em 08/10/2026, prazo real 139 dias, lucro 14.985,30, sem repasse e sem entrada duplicada; minha receita 10.284,74); dois corretores (10% = 2.364,31 e fixo 1.000,00 → minha receita 11.649,05; duas comissões; compensar R$ 500 do A não mexe no B); soma acima dos honorários recusa e não grava nada; formato antigo de um corretor; vínculos (salvar, mostrar, filtrar, editar); anexo (tipo inválido recusado, download autenticado, convidado redirecionado ao login, remoção). Ajustados dois testes antigos que procuravam os rótulos anteriores ("Rateio entre categorias", "Corretor (opcional)").

### Pendências
1. Corretores pré-cadastrados no processo/requisitório (pré-preencher a lista) — não há cadastro de processos; fica para a etapa de Corretores.
2. Lucro do requisitório na categoria "Juros Ganho (RPV / Precatórios)" nos relatórios: hoje o lucro aparece no requisitório; a entrada do requisitório fica zerada para não duplicar o caixa.
3. Conferido no banco: o caso real do Antônio Sérgio (recebimento #251, 08/10/2026, R$ 78.810,26) já está íntegro: requisitório #41 (1007715-60.2023.4.01.3701) em Recebido em 08/10/2026 com R$ 55.167,18, pelo vínculo anterior. Nada foi refeito.
4. Vínculo com "bem" (Etapa 6) ainda não existe.

## 05/11/2026 — Etapa 7: Dashboard personalizável e evolução do Fluxo de caixa (Auditor Fin)

Base: commit `20d9037` (fim da Etapa 6). Nenhum dado financeiro foi criado, alterado ou apagado; a migration só adiciona uma coluna.

### Dashboard (`/dashboard`)
- **Filtro de período** no topo: Este mês, Últimos 3/6/12 meses (meses fechados, incluindo o atual) e Intervalo livre (De/Até; datas invertidas são trocadas; inválidas voltam a "Este mês").
- **Fluxos** (receitas, despesas, resultado próprio, entradas/saídas, despesas por categoria) respeitam o período. **Posições** (saldo bruto/terceiros/caixa próprio, saldos por conta, faturas, valores a receber, empréstimos) usam o **último dia do período, limitado a hoje**, com rótulo "Posição em dd/mm/aaaa". Quando a posição é hoje, o saldo é calculado exatamente como em /banking (todos os lançamentos liquidados).
- **Personalizar**: mostrar/ocultar cada bloco, reordenar arrastando pelo ⠿ (computador) ou pelos botões ↑ ↓ (celular/teclado, com aria-label), escolher tipo de gráfico quando faz sentido (Entradas/Saídas e Médias móveis: barra/linha/área; Despesas por categoria e Distribuição: pizza/barras; Requisitórios por etapa: barras/pizza). Números continuam números. "Restaurar padrão" pede confirmação e só redefine o layout (o período é mantido).
- Preferências salvas **por usuário** em `users.dashboard_preferences` (JSON normalizado: blocos desconhecidos são descartados, blocos novos entram no fim, tipos inválidos voltam ao padrão). Persistem após recarregar.
- **Blocos**: Saldo bruto/Terceiros/Caixa próprio; Resultado próprio com comparação com o período anterior (valor e %, "sem base" quando o anterior é zero); Entradas e saídas por mês; Médias móveis 3/6/12 (números + linha com média móvel de 3 meses); Faturas a vencer/vencidas; Valores a receber (principal e com encargos); Empréstimos em aberto (Etapa 6); Saldo com corretores (adiantamentos a compensar e comissões a pagar, posição atual); Requisitórios a receber nos próximos 6 meses (somente Pago, Peticionamento e Aguardando recebimento, nunca Monitorar; previsão = pagamento + meses estimados; previsões já vencidas aparecem à parte, fora do total); Despesas por categoria; Saldos por conta separando contas próprias e de repasse; Patrimônio consolidado, Distribuição, Investimentos, Requisitórios por etapa, Sociedade e Faturas próximas (posição atual do snapshot). **Bens**: não há módulo; nada foi inventado (aviso no bloco de patrimônio).
- Atalho **Lançamento** continua abrindo o modal único (`?novo=expense`), que já tem "Paguei por alguém".
- Gráficos em SVG gerados no servidor (sem biblioteca/CDN nova), responsivos, com `role="img"`, título e tabela de dados para leitor de tela.

### Fluxo de caixa (`/banking/reports/cashflow`)
- Filtros: Mês (atalho mantido), De/Até livre, Conta, Categoria e Pessoa (titular do cartão — Cassio, Luciane, filho, contato — ou contato vinculado ao lançamento, a Valores a receber ou a Empréstimos). Filtros ficam na URL.
- **Comparação** com o período anterior de mesmo tamanho (valor e %; divisão por zero tratada).
- **Saldo nas contas / Dinheiro de terceiros / Saldo próprio** pelo mesmo `CashPositionService` de /banking, na data de posição.
- **Realizado x previsto**: realizado = liquidados; previsto = lançamentos pendentes + saldo das faturas que vencem no período + recorrências ativas ainda não geradas + requisitórios adquiridos (Pago/Peticionamento/Aguardando) com previsão no período, com lucro e capital separados. Monitorar nunca entra. Lista dos itens previstos.
- **Projeção 3/6/12 meses** a partir do saldo próprio de hoje (ou da conta filtrada), mês a mês, com gráfico de linha e tabela. Requisitórios com previsão já vencida não entram (seriam R$ 1,29 mi no "amanhã"); a tela informa quantos são e o valor.
- Gráfico mensal de receitas/despesas/resultado com escolha barra/linha/área.
- **Exportar CSV e PDF** (`/banking/reports/cashflow/export/{csv|pdf}`), só autenticado, com os mesmos filtros, valores reais e horário de Fortaleza. CSV em UTF-8 com BOM, separador `;` e vírgula decimal (abre direto no Excel).
- Regras preservadas (lógica movida sem alteração para `CashflowReportService`): cartão por competência = data da compra e por caixa = pagamento da fatura; principal a recuperar/emprestado não é despesa; principal devolvido não é receita; juros recebidos são receita; transferências, repasses e adiantamentos fora do resultado próprio; Juros Ganho e Retorno de capital dos requisitórios sem duplicar.

### Arquivos
- Novos: `app/Domains/Dashboard/Support/ReportPeriod.php`, `DashboardPreferences.php`, `app/Domains/Dashboard/Services/DashboardWidgetService.php`, `app/Domains/Banking/Services/CashflowReportService.php`, `app/Http/Controllers/CashflowExportController.php`, `resources/views/components/dashboard/chart.blade.php`, `chart-switch.blade.php`, `resources/views/pdf/banking/cashflow.blade.php`.
- Alterados: `resources/views/livewire/dashboard/index.blade.php`, `resources/views/livewire/banking/reports/cashflow.blade.php`, `CashPositionService` (parâmetro opcional de data), `User` (cast), `routes/web.php` (rota de exportação).

### Migration
- `2026_11_05_700000_etapa7_dashboard_preferences` — coluna `users.dashboard_preferences` (JSON, nula), reversível. Backup lógico antes: `/root/app-cassio-backups/etapa7-users-antes-migration-20261105.json`.
- Assets recompilados (`npm run build` no container do app; o build anterior era de 27/10). Backup do build anterior: `/root/app-cassio-backups/etapa7-public-build-antes-20261105`.

### Testes
- `tests/Unit/ReportPeriodTest.php` (4): períodos, data de posição, período anterior, variação com base zero, normalização/ordem/visibilidade/tipos de gráfico.
- `tests/Feature/Dashboard/Etapa7DashboardFluxoTest.php` (11): persistência por usuário e após recarregar, arrastar/restaurar sem mexer em dados, renderização e atalho Lançamento, fluxos x posições, requisitórios sem Monitorar, filtros conta/categoria/pessoa, regime caixa/competência + comparação, empréstimo (principal fora, juros dentro), previsto/projeção com pendentes, faturas e recorrências, faturas a vencer/vencidas, exportações autenticadas.
- Suíte completa verde.

### Pendências para o Cassio
1. 18 requisitórios adquiridos estão com a previsão de recebimento vencida (R$ 1.294.908,13 estimados). Revisar "meses estimados"/data de pagamento para que voltem à projeção.
2. Bens (imóveis, veículos) ficam para a Etapa 8.
