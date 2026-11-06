# Registro de alterações

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
