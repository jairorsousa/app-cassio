# Registro de alterações

## 2026-11-11 — Recebimento de processo: honorário principal e honorários extras

Pedido do Cassio (t282u / t286u): a parte de honorários do Recebimento de processo estava confusa, e às vezes ele cobra um honorário principal e um extra no mesmo processo.

1. A seção passou a se chamar **Meus honorários**, com o **Honorário principal** (valor ou % da entrada, o mesmo campo de antes) e o botão **+ Adicionar honorário extra**. Cada linha extra tem tipo (Extra ou Outro), descrição, valor em R$ e a caixa **Entra na base do escritório e do corretor**, desmarcada por padrão.
2. A tela mostra o **Total dos honorários** e, separado, a **Minha receita**. O rótulo **Comissão do escritório** virou **Parte do escritório (Mota e Silva Advogados)**. Há um texto curto de ajuda.
3. Extra lançado na mesma entrada sai do saldo do cliente e entra na Minha receita. Não entra no percentual do escritório nem do corretor, salvo se a caixa estiver marcada. A soma continua fechando com o valor do banco.
4. Extra cobrado depois: dá para marcar o recebimento como **só honorário extra do mesmo processo** (exige o número do processo). Nesse caso o padrão é sem comissão de escritório ou corretor. A categoria é a mesma da Minha receita.
5. Recebimentos já existentes não mudam (ficam sem extras). Em **Editar divisão** dá para acrescentar extras, com as mesmas proteções de hoje (repasse feito ou comissão paga) e a compensação automática de adiantamentos.

Migração: `2026_11_11_060000_add_process_receipt_fee_extras` (`process_receipts.is_extra_only` e tabela `process_receipt_fee_items`), rodada antes do código entrar no ar. Testes: `tests/Feature/Banking/HonorariosExtrasTest.php` (e ajuste de rótulo em `ProcessReceiptWithoutBrokerTest.php`). Backup: `/root/app-cassio-backups/honorarios-extras-20261111/`.

## 2026-11-11 — Botão "← Voltar" padronizado em todas as telas internas

- Novo componente único `<x-back-button>` (seta + "Voltar", área de toque de 44 px, no topo, visível no celular). Leva ao "pai" lógico da tela; se a página anterior era exatamente esse pai, usa o histórico do navegador para manter filtros e rolagem. `<x-banking.back-button>` virou apenas um atalho para ele.
- Telas ajustadas: Divisões (grupo, no lugar do link pequeno "‹ Divisões"); Investimentos (Carteira, Movimentações, Proventos, Posição B3, Extratos, Bens, Ativos, Rentabilidade → Visão geral); Financeiro (Contas, Categorias, Cartões, Lançamentos, Recorrentes, Fluxo de caixa → Visão geral; Faturas → Cartões; Importar extrato → Lançamentos; Empréstimos e Valores a receber padronizados, com "Voltar à lista" no detalhe); Requisitórios (ficha, formulário e relatórios → kanban/ficha); Sociedade (detalhe → lista, demais telas → sociedade); Corretores (ficha, adiantamentos, comissões e formulários); Contatos (ficha e formulário); Configurações (Usuários, Auditoria, Solicitações de identidade → Configurações).
- Sem mudança de regra de negócio nem de dados.
- Testes: `tests/Feature/BackButtonsTest.php` (7 testes). Backup do código: `/root/app-cassio-backups/voltar-20261111/`.

## 2026-11-11 — Juros simples ou compostos em Empréstimos e Valores a receber

Pedido do Cassio (card t280s1513): poder escolher o tipo de juros em todos os campos, começando em Simples.

1. Em **Empréstimos** e **Valores a receber**, ao lado de cada taxa de juros (juros combinados e, nos valores a receber, também a mora), há o seletor **Simples / Compostos**. O padrão é Simples. A tela mostra a prévia do total nos dois tipos (ex.: "Simples: R$ 1.060,00 · Compostos: R$ 1.061,21"), no formato R$ 1.000,00, no celular e no computador.
2. Fórmulas, com a mesma periodicidade já usada (% a.m., n = dias/30): simples = P × (1 + i × n); compostos = P × (1 + i)^n. A multa continua sendo um percentual único, sem tipo. Juros só viram receita quando recebidos.
3. Registros já existentes ficam Simples e com o mesmo total de antes (nenhum saldo mudou). A edição permite trocar o tipo e recalcular: no empréstimo, enquanto não houver devoluções; nos valores a receber, em Encargos (baixas já registradas não são refeitas; só o saldo em aberto é recalculado). O tipo aparece nos detalhes, junto da taxa.
4. Não há outros campos de juros editáveis no sistema (as taxas de aluguel de investimentos e a rentabilidade dos requisitórios não são campos de juros contratados).

Migração: `2026_11_11_053000_add_interest_type_to_loans_and_receivables` (`loans.interest_type`, `receivables.interest_type` e `receivables.late_interest_type`, padrão `simple`), rodada antes do código entrar no ar. Testes: `tests/Unit/SimpleInterestTest.php`, `tests/Feature/Banking/InterestTypeTest.php`. Backup: `/root/app-cassio-backups/juros-tipo-20261111/`.

## 2026-11-11 — Paguei por alguém: correção do erro ao salvar e tela mais clara

- O campo/atalho **Paguei por alguém** fica no Financeiro › Lançamentos (botão no rodapé da despesa ou da compra no cartão) e abre Financeiro › Valores a receber.
- Erro encontrado no `laravel.log` (09/11/2026, 01:15, horário de Brasília): ao salvar “Multa S10, Heleno” (contato Heleno Mota, id 447), o MySQL rejeitou a data vazia em `interest_start_date` (`SQLSTATE[22007]`). A tentativa está em transação e **não deixou valor a receber nem saída bancária pela metade**.
- Correções: a máscara brasileira de dinheiro (ex.: 1.467,35) é normalizada antes de validar; data de juros vazia passa a valer a data do pagamento; erros de validação e falhas inesperadas aparecem dentro do formulário, em português, em vez de uma tela de erro.
- A tela explica o que acontece com o dinheiro, reordena os campos (quem, o quê, como pagou, quando devolve), mostra uma prévia antes de salvar e ficou mais fácil de usar no celular. A regra de negócio não mudou.
- Testes: `tests/Feature/Banking/Etapa6PagueiPorAlguemClarezaTest.php` (e ajustes em `Etapa6PagueiPorAlguemModalTest.php` e `Etapa6ValoresReceberEmprestimosTest.php`). Backup do código: `/root/app-cassio-backups/paguei-por-alguem-20261111/`.

## 2026-11-11 — Requisitórios: Confirmar Pagamento com despesas opcionais, modal de Assinatura e kanban

- **Despesas cartorais e Outras despesas agora são opcionais** no modal "Confirmar Pagamento" (kanban), no painel de transição da ficha e no formulário: sem asterisco nem `required`; em branco valem 0 e não geram lançamento. "Valor pago ao cedente", "Data do pagamento" e "Conta de origem" continuam obrigatórios (agora também na ficha).
- **Card que não ia para Assinatura**: o modal de Assinatura não existia no kanban (o servidor marcava o modal como aberto, mas nada aparecia, e o card voltava). Modal criado, com data/hora, Cancelar, X e Esc.
- **Confirmar Pagamento que "não fazia nada"**: o kanban abria o modal ao levar um card de Cessão Pendente direto para Pagamento, mas o servidor só permite Pagamento a partir de Assinatura. O erro ficava escondido atrás do modal e o valor pago e as despesas já tinham sido gravados no requisitório (gravação pela metade). Agora: (1) o kanban conhece as transições permitidas e, se o movimento não é permitido, devolve o card e mostra "Transição não permitida: Cessão Pendente → Pagamento. Mova antes para Assinatura."; (2) valor pago e despesas vão dentro da própria transição (nada é gravado se ela falhar), no kanban e na ficha; (3) erros aparecem dentro do modal.
- Modal de pagamento fecha por Cancelar, X, Esc ou clique fora; o card fica na etapa anterior. Despesas zeradas aparecem em branco ao abrir.
- Sem migração. Teste: `tests/Feature/Writs/WritPaymentModalTest.php`.

## 2026-11-11 — Cartões › Faturas: gerenciar, editar/mover/excluir compras, desfazer importação e fatura automática pelo ciclo

Pedido do Cassio (BB 1126): as compras importadas do extrato do cartão caíram na fatura errada e não havia um lugar fácil para editar, mover ou excluir a fatura ou as compras.

1. Em cada fatura, o menu **Gerenciar** (visível no celular e no computador): Fechar ou Reabrir, Editar vencimento e fechamento, e Excluir fatura. A exclusão só vale se a fatura estiver vazia e sem pagamento; com compras ou pagamento, o menu explica o que falta fazer.
2. Em cada compra: **Editar** (valor, data, descrição, categoria e fatura de destino), **Mover** e **Excluir** com confirmação. Dá para marcar várias e mover ou excluir de uma vez. Os totais das faturas são recalculados; saldos de conta só mudam se houver pagamento de fatura (compras no cartão não mexem na conta).
3. **Desfazer importação**: lista as importações deste cartão (arquivo, quantidade, total e prévia), pede confirmação e remove só o que veio daquela importação. Lançamentos feitos à mão permanecem.
4. Na **importação de extrato do cartão**, o padrão passou a ser colocar cada compra na fatura do ciclo (data da compra + dia de fechamento). Ainda é possível escolher uma fatura só para todas. Se a data não combinar com a fatura escolhida, o sistema avisa na revisão; a fatura de cada linha pode ser trocada antes de gravar.

Diagnóstico só de leitura do cartão BB 1126 (nada aplicado nos dados reais): 14 das 16 compras importadas estão na fatura nov/2026, fora do ciclo; as parcelas LATAM 1/3 e 2/3 (R$ 580,55 em set e out) não são duplicatas. A correção desses lançamentos aguarda o OK. Lista completa: ver CSV do diagnóstico.

Migração: `2026_11_11_020000_statement_import_batches` (lote da importação e `transactions.import_batch_id`). Testes: `tests/Feature/Banking/InvoiceManageTest.php`. Backup: `/root/app-cassio-backups/faturas-gerenciar-20261111/`.


## 2026-11-11 — Empréstimos: correção do salvar, devolução de vários, editar/excluir e Voltar

- **Erro ao salvar "Novo empréstimo concedido"**: com o vencimento vazio, o formulário enviava data `''` e o MySQL (modo estrito) recusava com "Incorrect date value", gerando a tela de erro (nada era gravado). `LoanService` agora normaliza vencimento/observações vazios para NULL e juros vazio para 0. Mesmo defeito corrigido em `ReceivableService` (`interest_start_date` vazio passa a usar a data do lançamento).
- **Registrar devolução** (botão no topo e no detalhe): pessoa, conta, data e valor; lista de empréstimos em aberto com marcar/desmarcar; distribuição automática do mais antigo para o mais novo ou valor manual por empréstimo; prévia "Saldo na data / Abatido / Fica em aberto". Gera **um único lançamento de entrada** ligado a todos os empréstimos (baixas com a mesma chave). Juros só viram receita quando recebidos (categoria Juros de empréstimos apenas se houver juros). Estorno desfaz a devolução conjunta inteira.
- **Gerenciar → Editar**: altera todos os campos e ajusta o lançamento de saída (conta, valor, data, descrição). Com devoluções registradas, só descrição e observações mudam (aviso explica o motivo).
- **Gerenciar → Excluir**: pede confirmação citando valor e conta e desfaz a saída; com devoluções fica bloqueado com orientação para estornar antes.
- **Botão Voltar** (celular e computador) em Empréstimos (lista e detalhe) e em Valores a receber — componente `x-banking.back-button`.
- Sem migração. Teste: `tests/Feature/Banking/LoansManageTest.php`.

## 10/11/2026 — Recebimento de processo: compensação automática dos adiantamentos do corretor (criação e edição)

Pedido do Cassio: no passo "3. Corretores", só marcar e o sistema abater os adiantamentos do mais antigo para o mais novo, mostrando quanto fica a compensar em cada um.

1. Cada corretor do recebimento ganhou a caixa **Compensar automaticamente com os adiantamentos**. Marcada, o sistema abate a comissão nos adiantamentos pessoais em aberto daquele corretor, do mais antigo para o mais novo (data, depois ordem de cadastro), até acabar a comissão ou os adiantamentos.
2. Tabela por adiantamento: Disponível, Compensar, Fica a compensar, com totais e a linha "Comissão − compensado = a pagar ao corretor". Desmarcando, volta para os campos manuais (como antes).
3. O cálculo é refeito no servidor ao gravar (não confia no que veio da tela) e só usa adiantamentos daquele corretor.
4. **Edição** (Editar → Salvar Lançamento, ou Ver recebimento de processo → Editar divisão): ao reabrir, as compensações já feitas por este recebimento aparecem preenchidas e o que ele compensou volta a contar como disponível. Ao gravar, as compensações dele são desfeitas e refeitas (manual ou automática), igual a uma criação, sem duplicar a comissão. Repasse ao corretor continua bloqueando a edição.
5. "Editar divisão" passa a aparecer quando a única coisa feita no recebimento são compensações de adiantamento do corretor. "Remover divisão" continua exigindo desfazer as compensações antes.

Sem migração e sem alteração de dados. O lançamento real de 06/10/2026 (José Pereira de Araújo, #1537) não foi alterado; a compensação dele aguarda o OK do Cassio. Testes: `tests/Feature/Banking/CompensacaoAutomaticaTest.php`. Backup: `/root/app-cassio-backups/compensacao-auto-20261110/`.

## 10/11/2026 — Financeiro › Lançamentos: ordenar por coluna (Auditor Fin)

Pedido do Cassio: acompanhar a lista na mesma ordem dos extratos (ora do início do mês, ora do fim).

1. Cabeçalho Data, Descrição, Categoria, Conta e Valor com seta clicável: um toque ordena por aquela coluna (crescente), outro toque inverte. A seta laranja mostra a coluna e a direção ativas. Padrão continua: data mais nova em cima.
2. Celular: "Ordenar por" (Data/Descrição/Categoria/Conta/Valor) + botão ↑ Cresc. / ↓ Decresc. acima da lista.
3. Vale também para extratos de conta e faturas de cartão (mesma tela). Funciona com os filtros e o seletor 25/50/100; ao trocar a ordem volta para a página 1; "Limpar filtros" não mexe na ordem.
4. Lembrado por usuário (`users.preferences.sort.transactions`), lista fechada de colunas/direções (inválido volta ao padrão).
5. Categoria e Conta pelo nome exibido (recebimento de processo: categoria dele; rateado: o menor nome entre as categorias do rateio; sem categoria/sem conta primeiro no crescente). Valor pelo valor com sinal (despesa e pagamento de fatura negativos). Desempate: data e id (na ordenação por data, na mesma direção; nas demais, mais novo primeiro).
6. Não há saldo acumulado por linha nesta tela, então nada a recalcular.
7. Componente `x-fx.table` aceita cabeçalho personalizado (slot `head`), sem mudar as outras tabelas.

Sem migração. Testes: `tests/Feature/Banking/TransactionsSortTest.php`. Backup: `/root/app-cassio-backups/ordenar-colunas-20261110/`.

## 10/11/2026 — Datas sugeridas no "hoje do escritório" (Fortaleza) + data do lançamento #198 (Auditor Fin)

Pedido do Cassio: depois das 21h (UTC-3) várias telas sugeriam/gravavam a data de amanhã porque usavam o "hoje" do relógio em UTC.

1. Datas sugeridas e gravadas automaticamente passam a usar `App\Support\BusinessDate` (America/Fortaleza): Divisões (despesa e acerto), pagamento de Bens, Requisitórios (pago/finalizado no formulário, na ficha, no kanban e nos lançamentos automáticos), Corretores (comissões, adiantamentos, lançamentos, pagamento de comissão e despesa automática, período padrão e nome do PDF), Sociedade (despesas, aportes, distribuições, entrada, filtros do mês e relatório), recorrência (início), cotação manual, relatório de rentabilidade, recebíveis e o filtro do mês em Lançamentos.
2. O relógio do sistema continua em UTC; datas digitadas pelo usuário não mudam. Os jobs noturnos (recorrências às 00:05 UTC e fechamento de faturas às 00:10 UTC) e os cálculos internos de Investimentos/Painel não foram alterados.
3. Dado: lançamento #198 ("Recebimento requisitório", R$ 0,00, conta 3) teve só a data trocada de 11/10/2026 para 10/10/2026 (aprovado). Requisitório #41 não foi alterado; saldos conferidos iguais.

Sem migração. Testes: `tests/Feature/BusinessDateDefaultsTest.php` (simula 22:00 UTC-3). Backup: `/root/app-cassio-backups/datas-fortaleza-20261110/`.

## 10/11/2026 — Financeiro › Lançamentos: escolher 25, 50 ou 100 por página (Auditor Fin)

Pedido do Cassio: poder escolher quantos lançamentos ver por página (25, 50 ou 100).

1. Seletor "Por página: 25 / 50 / 100" ao lado da paginação, no celular e no computador (padrão 25). Só esses três valores são aceitos; qualquer outro volta para 25.
2. Ao trocar, volta para a página 1 e mantém filtros (período, categoria, conta/cartão, situação, tipo, titular) e ordenação. "Limpar filtros" não mexe nessa escolha.
3. A escolha fica salva por usuário (`users.preferences.per_page.transactions`), sem migração. Faturas de cartão e extratos de conta usam a mesma tela, então também têm o seletor.
4. No celular aparece também "1 a 100 de N" ao lado do seletor.
5. Correção junto: salvar a página Configurações não apaga mais outras preferências guardadas do usuário.

Sem migração. Testes: `tests/Feature/Banking/TransactionsPerPageTest.php`. Backup: `/root/app-cassio-backups/por-pagina-20261110/`.

## 10/11/2026 — Divisões: categoria do Financeiro do Cassio para a parte dele (Auditor Fin)

Pedido do Cassio: escolher a categoria da parte dele entre as categorias do Financeiro dele (com subcategorias e busca, também no celular), sem criar categorias novas.

1. Nova coluna `division_expenses.owner_category_id`: a categoria do Cassio fica separada da categoria do grupo (que os outros participantes veem). Os participantes não veem nem dependem dela.
2. No formulário da despesa (Cassio): campo "Sua categoria no Financeiro" com busca e subcategorias; a parte dele — paga por ele (lançamento da conta/cartão ou lançamento vinculado) ou por outro sócio ("sua parte") — usa essa categoria. Compra do bem continua sempre em "Aquisição de bem".
3. "Trocar categoria" em qualquer despesa já lançada (qualquer pagador, qualquer autor), no Divisões e no Financeiro (link nos lançamentos "Divisões" e "sua parte"). Muda só a categoria dos lançamentos dele; valores e saldos iguais; não entra no histórico do grupo.
4. Despesas sem a categoria dele ficam destacadas, com aviso no topo do grupo e botão "Escolher categoria".
5. Despesas antigas: `DivisionService::backfillOwnerCategories()` copiou a categoria que cada lançamento já tinha (sem lançamento: sugestão pelo nome da categoria do grupo). Pizza (#5) colocada em "Restaurantes & Refeições" (a pedido).

Migração: `2026_11_10_210000_divisoes_categoria_propria`. Testes: `tests/Feature/Divisions/DivisionsOwnerCategoryTest.php`. Backup: `/root/app-cassio-backups/divisoes-categorias-20261110/`.

## 10/11/2026 — Divisões: só a sua parte no resultado + Bens: compra em sociedade (Auditor Fin)

Pedido do Cassio (fazenda João Barbudo e grupos do Divisões):

1. **Regra geral para todos os grupos.** Quando o Cassio paga uma despesa dividida, a conta continua com a saída inteira, mas no resultado e nos gráficos de despesa entra só a parte dele; o resto é adiantamento aos sócios (a receber, fora do resultado). Quando outro sócio paga, a parte do Cassio vira um lançamento **sem conta e sem cartão** (`source_type = division_share`, só leitura, na data e categoria da despesa), que não mexe em saldo nenhum. Acertos (`division_settlement`) não são receita nem despesa. Grupo zerado ⇒ despesas do Cassio = exatamente a parte dele.
2. **Retroativo** sem mexer em saldo: as regras do resultado valem para tudo; os lançamentos da parte foram gerados por `DivisionService::rebuildOwnerShares()` (idempotente).
3. **Bens › Compra em sociedade.** Bem ligado a um grupo do Divisões (`properties.division_group_id`). Cada despesa de grupo ligado tem tipo **Despesa** (padrão) ou **Compra do bem** (`division_expenses.kind`). A parte do Cassio na compra vai para "Aquisição de bem" (fora do resultado); um só lançamento na conta. Quem paga a mais escolhe **Adiantamento aos sócios** (segue a divisão, vira crédito) ou **Aumentar minha parte** (`purchase_mode = increase`: parcela toda de quem pagou, sem dívida, aumenta o %). O bem mostra % e quanto cada sócio pagou, total pago pelo grupo × valor total e quanto falta; patrimônio = valor atual × % do Cassio. "+ Pagamento" num bem em sociedade abre a despesa no Divisões já como compra.
4. **Dados reais:** criado o bem "Fazenda João Barbudo" (imóvel rural, dono Cassio, grupo #3) sem valores (aviso "informe o valor total"); despesas #1 e #4 marcadas Compra do bem / adiantamento; lançamento #1482 passou para "Aquisição de bem" (saldo igual); #7 e #8 seguem Despesa.

Migração: `2026_11_10_190000_divisoes_bens_sociedade`. Testes: `tests/Feature/Divisions/DivisionsSocietyTest.php`. Backup: `/root/app-cassio-backups/divisoes-bens-sociedade-20261110/`.

## 06/11/2026 — Padrão Cancelar / Salvar (+ Salvar e novo só em série) (Auditor Fin)

Escopo pedido pelo Cassio:

1. Em **todos** os formulários de criação: rótulos **Cancelar** e **Salvar**.
2. **Salvar e novo** somente onde se lança em série:
   - Financeiro › Lançamentos (já existente)
   - Investimentos › Proventos
   - Investimentos › Movimentações
   - Corretores › Registrar comissão / Novo adiantamento / Despesas de processos
3. Cadastros únicos ficam só com Cancelar + Salvar (sem Salvar e novo): empréstimo, valor a receber, requisitório, contato, corretor, conta, cartão, categoria, recorrência, ativo, bem, tipos de caso, sociedade (aporte/despesa/distribuição).
4. Em edição: Cancelar + Salvar (sem o terceiro botão). Reset do formulário só após sucesso; em erro os dados permanecem.

## 06/11/2026 — Lançamentos: rótulos Cancelar / Salvar / Salvar e novo (Auditor Fin)

Somente rótulos e ordem no rodapé dos formulários de criação (despesa no cartão, despesa, receita, transferência). Comportamento inalterado.

1. **Cancelar**
2. **Salvar** — grava e fecha (antes: Criar Lançamento)
3. **Salvar e novo** — grava, mantém o modal aberto e limpa o formulário (antes: Salvar e criar outro)
4. Edição permanece **Cancelar** + **Salvar Lançamento** (sem o terceiro botão).

## 06/11/2026 — Lançamentos: Salvar e criar outro (Auditor Fin)

Pedido do Cassio no modal **Nova despesa no cartão de crédito**: após gravar, poder abrir imediatamente outro formulário em branco sem fechar a janela.

1. Botões no rodapé (somente criação; edição permanece Cancelar + Salvar Lançamento):
   - **Cancelar** — fecha sem gravar
   - **Salvar e criar outro** — grava com a mesma validação de **Criar Lançamento**, mantém o modal aberto e limpa o formulário
   - **Criar Lançamento** — grava e fecha (comportamento anterior)
2. Após sucesso do “Salvar e criar outro”: limpa valor, descrição, categoria/rateio, cartão/conta, parcelas, primeira fatura, notas, vínculos, comprovante e “Paguei por alguém” (atalho); mantém data atual e demais padrões iniciais; foco no campo Valor; listagem atualizada (`resetPage`); mensagem de sucesso no próprio modal.
3. Erro de validação preserva o que foi digitado; `createAnotherAfterSave` não fica preso após falha; loading desabilita os três botões para evitar clique duplo.
4. O mesmo botão vale nos modais compartilhados de **Nova despesa**, **Nova receita** e **Nova transferência** (create mode), com a mesma nomenclatura.
5. Nenhum lançamento real de produção foi criado nos testes.

Arquivos: `resources/views/livewire/banking/transactions/index.blade.php`, `tests/Feature/Banking/TransactionFlowTest.php`.


## 06/11/2026 — Lançamentos: filtro Conta/Cartão em pills horizontais (Auditor Fin)

Pedido do Cassio: o campo **Conta** em Financeiro → Lançamentos estava confuso (tipos empilhados/comprimidos no seletor, sem opção Cartão).

1. Linha principal de filtros limpa: De, Até, Tipo, Categoria, **Conta ou cartão** (dropdown simples), Status — sem pills empilhadas dentro do campo.
2. Abaixo, pills horizontais curtas **Tipo de conta ou cartão**: Todas, Corrente, Investimento, Fundo, Dinheiro, Repasse, Cartão (só tipos que existem no cadastro; rótulos curtos via `typeShortLabel`).
3. Ao escolher o tipo, o dropdown lista só as contas daquele tipo; em **Cartão**, lista os cartões. Em **Todas**, optgroups de contas + Cartões.
4. Backend filtra de verdade: tipo bancário (`bank_account_id` / `whereIn` por tipo), todos os cartões (`credit_card_id IS NOT NULL`) ou cartão específico. Seleção de cartão persiste como `account=card:{id}` (evita colisão de id com conta). Query URL: `accountType` + `account`.
5. `clearFilters` zera também `accountType`. Demais filtros, paginação, OFX, titular, vínculos e dados financeiros intactos. Formulários de lançamento continuam com o account-picker antigo.
6. Testes: `TransactionAccountCardFilterTest` (6). Suíte completa: 643 ok; 1 OfxImport flaky isolado passou na reexecução.

Arquivos: `resources/views/livewire/banking/transactions/index.blade.php`, `tests/Feature/Banking/TransactionAccountCardFilterTest.php`.




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

### Correção pós-Etapa 7 — arrastar no Dashboard (05/11/2026)
- Causa: o handler de soltura usava `this.$el` dentro do método do `x-data`; no Alpine isso aponta para o bloco onde o evento ocorreu, não para a grade, então a lista de blocos vinha vazia e a ordem não mudava. Além disso o bloco inteiro era `draggable` (conflito com botões/checkbox) e não havia `dataTransfer.setData` (exigido pelo Firefox).
- Agora: o handle ⠿ (desktop) é o único elemento arrastável, com `setData`/`effectAllowed`/imagem do bloco; cada bloco é alvo de soltura com destaque visual; ao soltar chama `moveWidgetTo(origem, destino)` e a nova ordem é calculada e salva no servidor (`DashboardPreferences::moveTo`). Setas ↑ ↓, teclado e mobile inalterados.
- Testes: `moveTo` (unitário) e arrastar para cima/baixo, alvos inválidos, convivência com setas e persistência (feature). Nenhum dado financeiro alterado.
- Filtro Pessoa no Fluxo mostrando só Todas: comportamento correto. As opções vêm de titulares de cartão definidos e de contatos vinculados a lançamentos, Valores a receber ou Empréstimos; nos dados reais os 8 cartões estão sem titular e não há vínculos de contato, valores a receber nem empréstimos. Nenhuma pessoa foi criada.

### Correção pós-Etapa 7 — sessão expirada (419) no Livewire (05/11/2026)
- Causa da perda após o arraste: o POST `/livewire/update` voltou 419 (sessão/CSRF expirados; `.env` usa `SESSION_LIFETIME=60`, além do middleware de inatividade). O servidor recusou corretamente e nada foi salvo, mas a tela já mostrava a nova ordem, sem aviso.
- Novo `resources/js/session-expired.js`, registrado em `app.js` (hook global `request` do Livewire, só neste app): em status 419 cancela o tratamento padrão e mostra aviso fixo (`role=alert`) "Sua sessão expirou. Recarregue a página para acessar novamente antes de salvar." com botão **Recarregar**. Um único aviso; nada recarrega sozinho (sem loop). No Dashboard o botão leva a `/dashboard?personalizar=1`, que abre o modo Personalizar.
- CSRF continua ativo; nada foi liberado. Com sessão válida o arraste salva normalmente (`moveWidgetTo`). Setas inalteradas.
- Testes: POST Livewire sem token → 419 sem gravar preferências; aviso/handler presentes no JS-fonte e no build publicado; `?personalizar=1` abre Personalizar. Nenhum dado financeiro alterado.
- Ajuste: o Dashboard é #[Lazy], então o mount roda no POST do Livewire e não via ?personalizar=1. A raiz do componente agora lê a query após carregar, remove-a da URL (history.replaceState) e ativa Personalizar ($wire.set). Teste de render: padrão desligado, ativação ligada, leitor presente.

## 2026-11-05 — Etapa 8: importação B3 (Midas) com prévia + Bens

- Novas tabelas (migração `2026_11_05_800000`, só cria tabelas; backup prévio `storage/backups/pre_etapa8_*.json`): `investment_import_batches`, `b3_position_snapshots`, `asset_income_events`, `properties`.
- `B3MidasImportService` + página **Investimentos › Posição B3** (`/investments/b3`) e comando `investments:b3-import {dir}` (padrão: só prévia; `--confirm --user=` grava). Fluxo: upload → prévia (nada gravado) → confirmação explícita; hashes sha256 conferidos na confirmação; checagem contra `meta.json`.
- Posição exibe fonte B3, data-base, custódia × doador; PM desconhecido fica `null` ("não informado"), nunca zero. SBFG3 (custódia+empréstimo) sem divisão inventada: aviso até a fonte trazer `quantidade_custodia`/`quantidade_emprestada`.
- Proventos líquidos ficam só em Investimentos (`asset_income_events`, sem observer): nenhum lançamento no Financeiro. Reembolso de aluguel gravado como `lending_reimbursement`; campos `lending_contract_ref`/`lending_fee_rate` reservados para importar taxa/contrato depois. Corretora como texto, sem vincular conta.
- Idempotência por `source_key` (posição: data-base+ticker; provento: hash da linha + ordinal para linhas idênticas).
- Bens (`/bens`): cadastro com anexo privado; valor atual dos ativos soma ao patrimônio do dashboard.
- Dashboard: sem posição calculada por operações, usa a posição B3 confirmada (rotulada com data-base), sem somar as duas.
- Correção lacuna 1 (`OwnFinancialService`): compra de ativo não é despesa; venda conta só ganho realizado positivo. Saldo de caixa inalterado.
- Importação real **não executada** — aguarda prévia/confirmação do Cassio.
- Testes: `Etapa8B3ImportBensTest` (10); suíte completa 456 passed.

## 2026-11-05 — Etapa 8: correção do comando de prévia B3

- Bug achado na 1ª prévia real: pasta sem permissão de leitura para o usuário do container fazia o comando devolver prévia "ok" vazia. Agora falha com erro claro para pasta inexistente/ilegível, arquivo sem permissão, nenhum CSV presente ou `meta.json` inválido. Teste `test_command_fails_on_missing_or_unreadable_files`. Nenhum dado importado.

## 2026-11-06 — Etapa 8: resumo de Investimentos reflete a posição B3

- Bug: após a importação B3 (#1), `/investments` mostrava R$ 0, 0 ativos e proventos 0 — `PortfolioProfitabilityService::summary()` só lia posições por operações e `asset_dividends`.
- Correção: sem posição por operações, o resumo usa a última posição B3 confirmada (rotulada com data-base), nunca somando as duas. Valor investido/ganho em aberto só com PM conhecido; PM ausente listado. Proventos totais/12M incluem `asset_income_events` (líquido, inclui reembolsos de aluguel). Aviso visível para ativos sem divisão custódia × emprestado (SBFG3), contados uma vez pela quantidade total da linha.
- Nenhum dado financeiro alterado (309 transações). Testes em `Etapa8InvestmentsSummaryB3Test`.

## 2026-11-06 — Cotações brapi (plano gratuito) para a posição B3

- `BRAPI_TOKEN` já configurado e válido (teste BBAS3/BTLG11/JBSS32 = HTTP 200). Plano gratuito, sem assinatura; cotação com atraso ~30 min.
- Agendamento: mantém 18:30 e adiciona a cada 30 min, dias úteis 10:00–17:30 (America/Sao_Paulo), job único sem sobreposição; tickers processados em sequência. Feriados da B3 não são tratados (a fonte devolve a última cotação).
- Escopo: só ativos em carteira — posição por operações ou a última posição B3 confirmada (31), não os ~60 ativos do histórico de proventos.
- `asset_quotes` ganha `market_time` (horário da fonte) e `source`. Resumo de Investimentos usa quantidade × última cotação (data ≥ data-base), senão o fechamento B3; exibe horário da cotação, atraso e o valor B3 da data-base. PM, transações e a foto B3 não são alterados.
- Job: timeout 600 s, único por 1500 s. Primeira execução manual: 31 tickers atualizados em ~7 s.
- Testes em Etapa8QuotesB3Test (4).

## 2026-11-06 — Dashboard e evolução usam a mesma carteira de /investments

- Bug: widgets do Dashboard (Investido, Mercado, Proventos 12M, Patrimônio › Carteira) mostravam 0 e a Evolução do patrimônio ficava zerada. Causas: (1) snapshot gravado pelo worker com código antigo (worker reiniciado no deploy); (2) `DashboardSnapshotBuilder` tinha cálculo próprio (investido 0, valor B3 sem cotações); (3) `portfolioEvolution` só considerava operações.
- Correção: o builder usa `PortfolioProfitabilityService::summary()` (fonte única: operações ou B3 + cotações brapi; proventos com importados). Evolução sem operações = ponto B3 da data-base + ponto atual (sem meses zerados inventados). Rodapé de /investments descreve brapi (atraso ~30 min), fallback B3 e PM ausente ≠ 0.
- Contas, saldos, transações (309) e proventos não alterados. Testes em `Etapa8DashboardPortfolioB3Test`.

## 2026-11-06 — Etapa 8: importação de extratos de corretoras (MVP genérico)

- Ambiente: nenhuma chave de IA configurada; sem pdftotext/tesseract, sem phpoffice no servidor. Reaproveitado padrão de prévia do OFX/B3. XLSX lido nativamente (zip + XMLReader).
- Novas tabelas `broker_statement_imports` (lote staging, hash, TTL 24 h, mapeamento, sugestão IA) e `broker_statement_rows` (linha normalizada: data, ticker, tipo, qtd, preço, bruto, IR, taxa, líquido, taxa de aluguel, id externo, `source_key`, status).
- Página Investimentos › Extratos (`/investments/extratos`): upload XLSX/CSV/PDF → prévia editável (mapeamento de colunas, tipo por linha, excluir, "é outro evento") → confirmação explícita. Só proventos/aluguel viram `asset_income_events` (`source=broker_statement`, sem Financeiro); compra/venda/transferência/taxa = "registrar manualmente".
- Deduplicação: duplicado exato por `source_key` é ignorado; cruzado com eventos existentes (inclui os 1845 da B3) por ticker + data ±3 dias + líquido ±0,01 vira "possível duplicado" e exige decisão humana. Nada existente é apagado.
- PDF: só texto via pdftotext se instalado; escaneado/sem extrator/sem layout → "precisa revisão", nada importado. Arquivo alterado após prévia invalida a confirmação.
- IA opcional (`STATEMENT_AI_URL/KEY/MODEL`): só sugere mapeamento com cabeçalho + 5 linhas; sem config nada é enviado. Limpeza horária `investments:statements-cleanup`.
- Layouts BTG/XP/BB/Itaú NÃO validados (sem arquivos reais). Testes com dados sintéticos: `BrokerStatementImportTest`.

## 2026-11-06 — poppler-utils (pdftotext) na imagem PHP

- `docker/php/Dockerfile`: adicionado `poppler-utils` (apk). Imagem reconstruída e recriados só `app`, `worker` e `scheduler` (`docker compose up -d --no-deps`); nginx, redis e cloudflared intocados. Backup do Dockerfile anterior em `storage/backups/Dockerfile.pre_poppler`. pdftotext 25.12.0.
- `PdfTextExtractor`: falha/timeout do binário vira "sem texto" (não derruba upload).
- Testes `BrokerStatementPdfTest` com PDFs sintéticos (`tests/fixtures/statements/`): extração real, PDF sem texto, PDF com texto continua "precisa revisão" e não importa nada, binário quebrado/ausente. Pulam se pdftotext não existir.
- Continua sem OCR, sem IA e sem layout de PDF validado de qualquer corretora; PDF só serve para triagem (texto detectado ou não).
- Observação: o worker roda `queue:work --timeout=120`, menor que o `timeout` 600 do job de cotações; não alterado aqui.

## 2026-11-06 — Parser BTG (PDF Conta Investimento) com reconciliação

- `BtgStatementPdfParser`: calibrado com 1 extrato real BTG (01/01–06/11/2026, 19 páginas) via `pdftotext -layout`. Débito/crédito decidido pelo saldo corrido (anterior ± valor = saldo), não pela coluna. Reconciliação: saldo inicial + créditos − débitos = saldo corrido final e = "Total de Créditos/Débitos" impressos; qualquer linha divergente bloqueia a confirmação. "Saldo Final" impresso diferente do saldo corrido é sinalizado (no arquivo real: 51.498,34 impresso × 51.510,94 corrido, diferença = "Rendimento Disponível - Saldo Remunerado" 12,60 lançado após o fechamento).
- Classificação: TAXA REMUNERAÇÃO - BTC → aluguel (bruto); IR - BTC → vinculado à taxa do mesmo dia/ticker (líquido = bruto − IR); LIQ BOLSA (Corretagem BTC Aluguel)/taxa custódia/emolumentos → taxa (manual); LIQ BOLSA (ALUGUEL) → liquidação agregada (manual); DIVIDENDOS/JCP/RENDIMENTOS (à vista ou sem sufixo) → provento; "- ALUGUEL" → reembolso; RENDIMENTOS de ação = "Rendimento (ação)", de FII (…11) = rendimento FII; TED → transferência; LIQ BOLSA (Operacoes) → liquidação de operações (manual, sem qtd/preço); IRRF vendas → imposto; conta remunerada/saldo remunerado → manual; frações/subscrição → manual.
- Ticker: aceita B3SA3; quando o PDF corta a descrição, infere pelo nome do emissor em outra linha do mesmo extrato (aviso "conferir"); ambíguo/ausente → erro "informe o ticker" (campo editável na prévia).
- Comando `investments:statement-preview {arquivo}`: prévia somente leitura (transação revertida, arquivo apagado).
- Prévia do arquivo real (nada gravado; cópia temporária privada apagada): 667 movimentos, reconciliação OK (0 divergências). Confirmação NÃO executada.
- Testes `BtgStatementPdfParserTest` com layout anonimizado (`tests/fixtures/statements/btg_layout_anonimizado.txt`).

## 2026-11-06 — Extrato de conta em CSV (saldo corrido) — base para XP

- `BankAccountCsvParser`: CSV de conta com data, descrição, valor com sinal (ou débito/crédito) e saldo. Detecta ordem decrescente e inverte (sem reordenar por data, preservando lançamentos retroativos), reconcilia linha a linha (anterior + valor = saldo), deriva a abertura da 1ª linha e aponta lacunas com o valor faltante. Junta vários arquivos: ordena por período, remove repetições entre arquivos por impressão digital (data+descrição+valor+saldo+ordinal; iguais no mesmo arquivo seguem distintas) e confere a emenda de saldo.
- Classificação só para revisão ("outros movimentos", nenhum lançamento financeiro nem provento): rendimento automático/conta remunerada, transferência (TED/PIX), devolução (sem compensar com o PIX de origem), pagamento de financiamento, taxa, liquidação de operações, indício de provento, outro.
- Comando `investments:account-csv-preview {arquivos*}` (somente leitura, saída agregada).
- Layout XP NÃO validado: os CSVs reais citados não estavam no box. Testes com amostras sanitizadas: `BankAccountCsvParserTest`.

## 2026-11-06 — Parser BB Home Broker (PDF movimentação financeira)

- `BbHomeBrokerPdfParser`: calibrado com 1 PDF real BB (01/01–06/11/2026, 2 páginas, só PAG.RENDIMENTOS de FII). Lê data do lançamento, data do evento, custódia, evento, ticker (TTLO.), quantidade (QTDE) e valor creditado. "Total dos lançamentos no período" repete o total geral em cada página: usado só para conferência (não somado). Linha datada fora do layout ou soma ≠ total → não reconcilia (bloqueia confirmação).
- Integrado ao staging de extratos (layout `bb_home_broker`): rendimentos de FII como provento, possíveis duplicados com eventos B3 (ticker, ±3 dias, ±0,01) exigem decisão humana. Quantidade é a do evento, não posição/PM.
- Testes `BbHomeBrokerPdfParserTest` (layout anonimizado). Prévia real somente leitura via `investments:statement-preview` (transação revertida).

## 2026-11-06 — Comando de importação de extratos (mesmo fluxo da tela)

- `investments:statement-import {arquivo} --broker= --user= [--confirm]`: staging → retém para revisão (`held_review`) linhas com ticker inferido, ticker sem cadastro ou com erro → confirma só as prontas → candidatos a duplicado viram `duplicate_not_imported` (o lote preserva todas as linhas). Sem `--confirm` é dry-run em transação revertida.
- Dry-run real: BTG 667 linhas → 168 importáveis (166 aluguéis líquidos R$ 1.537,34 + 2 JCP R$ 280,27 = R$ 1.817,61), 47 duplicados não importados, 31 retidos (8 reembolsos com ticker inferido + 23 sem ticker), 141 IR vinculados, 280 manuais. BB 40 linhas → 0 importáveis, 40 duplicados. Confirmação real NÃO executada (contagem esperada pelo pedido divergiu).

## 2026-11-06 — Conferência Etapa 8: proventos por origem e aluguel BTC

- Posição B3 › proventos: tabela anual passa a ter coluna "Taxa de aluguel (BTC)" (antes ficava só no total); lista mostra IR e Origem (B3/Midas × extrato de corretora); resumo das taxas BTC com bruto − IR = líquido. Corretagem do aluguel segue pendência manual (não descontada).

## 2026-11-06 — Extratos: consulta das linhas do lote

- /investments/extratos: "Ver lançamentos" em lotes confirmados (somente leitura, só do próprio usuário). Filtros com contagem: Pendentes de revisão (padrão), Importados, Duplicados, Registro manual, IR vinculado, Todos; busca por ticker, descrição, data ou valor; 50 linhas por vez. Mostra situação, motivos e o evento B3 de que a linha é duplicada. Nada é importado nem editado por esta tela.
- Texto de formatos atualizado: validados BTG Conta Investimento (PDF) e BB Home Broker (PDF); demais entram com mapeamento genérico.
- Histórico de lotes restrito ao usuário logado. `/investments/bens` redireciona para `/bens`.

- Correção (HTTP 500 em "Ver lançamentos"): a contagem por situação usava a relação rows() (ordenada por row_number) com GROUP BY, recusada pelo MySQL com only_full_group_by. Contagem agora consulta sem ORDER BY; teste garante que nenhuma query agrupada da tela tem ORDER BY.

## 2026-11-06 — Ajustes mobile (Investimentos)

- Subnav de Investimentos: abaixo de lg vira uma linha só com rolagem horizontal (sem barra), mesma ordem e rótulos; desktop (lg+) inalterado.
- Posição B3: posições e proventos em cartões abaixo de md; tabelas só em md+ (sem mudança no desktop).
- Extratos › Ver lançamentos: linhas em cartões abaixo de md (data, ticker, tipo, valor com cor, descrição, situação e motivos); chips de filtro em uma linha rolável; busca com fonte menor no mobile.
- Só visual; nenhum dado alterado. CSS recompilado (`npm run build`).

- /bens: sem registros mostra estado vazio (sem tabela). Com registros, cartões abaixo de md (descrição, tipo/identificação, valor — de venda se vendido —, situação/data da venda, titular, valor atual, aquisição, anexo, Editar/Excluir com os mesmos métodos); tabela inalterada em md+.

## 2026-11-06 — Resultado parcial quando falta PM

- `PortfolioProfitabilityService::summary()`: novos `result_partial`, `known_cost_market_value`, `unknown_cost_market_value`. Ganho de capital já era só dos ativos com PM; o rótulo "Lucro total" sugeria total. Com PM faltando: card vira "Resultado informado" (ganho de capital com PM + proventos), "Valor investido (com PM)", nota com custo→valor dos ativos com PM e valor dos ativos sem PM (fora do ganho); "Retorno total" % mostra "não calculado". Sem mudança de dados.

## 2026-11-06 — Auditoria segurança/confiabilidade Etapa 8

- Nginx: `resolver 127.0.0.11 valid=10s ipv6=off` + `fastcgi_pass $app_upstream` (DNS do Docker re-resolvido; app recriado não deixa 502 por IP em cache). Validado com `nginx -t` em contêiner temporário e no ativo; aplicado com `nginx -s reload` (sem recriar serviços). Backup em `storage/backups/nginx.default.conf.pre_resolver`.
- Verificado sem mudança: anexos de Bens/extratos no disco local privado, rotas com auth; nenhum arquivo de extrato residual (lotes sem file_path); limpeza `investments:statements-cleanup` de hora em hora; cotações 30 min dias úteis + 18:30, sem jobs falhos.
- Pendente (não alterado): worker `--timeout=120` e `retry_after=90` menores que `timeout=600` do job de cotações.
- Resolvido: worker `queue:work --timeout=660` (docker-compose.yml) e `REDIS_QUEUE_RETRY_AFTER=700` no .env (não versionado; documentado em .env.example), acima do `timeout=600` do job de cotações. `queue:restart` + recriado só o worker (`up -d --no-deps --force-recreate worker`), sem rebuild. Config sem cache; app e worker leem retry_after=700. Agenda de cotações inalterada.
- Limpeza: removido 1 arquivo temporário do Livewire com mais de 24 h (livewire-tmp). Bens compartilhados entre usuários por decisão (uso doméstico).

## 2026-11-06 — Conferência de navegação (Investimentos)

- As 9 abas do subnav têm rota e renderizam (verificado como usuário real, sem gravação). Carteira, Proventos e Rentabilidade são baseadas em operações/proventos manuais (hoje vazias) e pareciam "sem dados" apesar da B3: aviso com link para Posição B3 quando há posição B3/proventos importados (`components/investments/b3-notice`). Sem remoção de telas nem novas rotas.
- Notas de corretagem: sem layout real ainda; nenhum parser criado. Quando chegarem, entram pelo importador de extratos (prévia/mapeamento) e viram compras/vendas só após parser validado.

## 2026-11-06 — Configurações visuais (após Etapa 8)

- Nova página `/configuracoes` (rota `settings`), no menu da conta (abaixo de Perfil); navegação principal inalterada.
- Preferências por usuário em `users.preferences` (JSON nulo; migração `2026_11_06_300000`), validadas por lista fechada em `App\Support\UserPreferences`:
  - Tema: Claro, Escuro, Automático (segue o sistema). Aplicado no servidor (`data-theme` no `<html>`) antes da primeira pintura; Automático resolve por `prefers-color-scheme` no script do `<head>`. O botão de lua do topo virou troca temporária da aba (sessionStorage).
  - Cor de destaque: Laranja (padrão), Azul, Verde, Roxo, Rosa, Petróleo, com tons para claro e escuro; prévia ao vivo.
  - Tamanho do texto: Padrão, Grande (112,5%), Extra grande (125%).
  - Densidade: Confortável, Compacta (menos espaço em tabelas e margens).
  - Área inicial: Painel, Financeiro (lançamentos), Investimentos — usada após login e em `/`.
- Salvar, Cancelar alterações e Restaurar padrões (carrega padrões; aplica ao salvar). Sem dados financeiros, permissões ou importações alteradas.
- Modo escuro: utilitários bg-white/gray herdados mapeados aos tokens; texto terciário do escuro mais claro (eixos e legendas de gráficos). Gráficos já usam tokens/currentColor.

## 2026-11-06 — Engrenagem de Configurações e Identidade visual

- Engrenagem (ícone `settings`, aria-label/título "Configurações") no fim da navegação principal, depois de Transmissão; no menu mobile, último item com rótulo. Link no menu da conta mantido.
- /configuracoes › Identidade visual: foto/logo do topo por usuário. Escolha PNG/JPG/WebP (até 5 MB e 4096 px, checado no navegador), ajuste Zoom, Posição horizontal e vertical com prévia; só o recorte quadrado 512 px em PNG é enviado (original não sai do aparelho). Servidor aceita só PNG 512×512 até 2 MB, reencoda com GD (sem metadados) e grava em `storage/app/private/branding/users/{id}.png` (privado, servido só ao dono em `/configuracoes/logo`). "Remover foto" volta ao "CM". Formulário separado das preferências de aparência.
- Diagnóstico "site não abre" (08:14): cloudflared sem erros, HTTPS público 200, nginx atendeu o celular às 08:12 (Fortaleza) com o CSS novo; erro de túnel era do lado do navegador/rede, não do servidor.

- 2026-11-06 (menu): ordem alterada para Corretores antes de Contatos, conforme pedido do Cassio. A navegação desktop e mobile usa a mesma definição; testes de navegação e configurações verdes.

## 2026-11-06 — Listas mais leves: Transmissão e Categorias

- Transmissão: a página não tem histórico de envios; o peso era a lista de contatos do Chatwoot guardada inteira (objeto completo) no estado do Livewire. Agora guarda só id/nome/telefone, mostra 20 por página com busca por nome/telefone (filtra antes de paginar e volta à página 1). O envio continua indo para todos os contatos da label. Nenhuma chamada nova ao Chatwoot; leitura de labels/contatos ao abrir a página é a mesma de antes.
- Categorias: 20 categorias-mãe por página em Despesas e Receitas (paginação separada); subcategorias carregadas só ao clicar "Ver N subcategoria(s)". Contagens totais e de transações inalteradas.

## 2026-11-06 — Transmissão fase 1: link, parágrafos e revisão antes do envio

- `App\Support\BroadcastComposer`: monta o texto enviado e a prévia com a mesma função. CRLF→LF; com "Separar parágrafos automaticamente" (padrão ligado) cada Enter simples vira linha em branco, 2+ enters ficam iguais (sem acumular). Link opcional (só http/https; sem esquema vira https://; javascript:/mailto:/data: rejeitados; nunca acessado pelo servidor) vai no fim, após linha em branco. Anexo até 12 MB mantido.
- Envio em duas etapas: "Revisar envio" abre a revisão com total da etiqueta, telefone válido/sem/inválido e repetidos (não removidos nesta fase), mídia, prévia e aviso de irreversível. Só "Confirmar e iniciar envio" enfileira; `send()` direto só abre a revisão; confirmar sem revisão ou após alterar mensagem/link/opção/mídia/etiqueta é recusado; trava de 15 s contra duplo clique. Intervalo 45–90 s e limpeza do anexo inalterados. Sem envio real nos testes (fila e HTTP falsos).


## 2026-11-06 — Transmissão fase 2: etiquetas múltiplas, deduplicação, fila por pessoa e áudio

- Etiquetas em cards de seleção múltipla, nenhuma marcada por padrão; contatos carregados por clique e guardados em cache por etiqueta.
- Deduplicação entre etiquetas por telefone E.164 (hash HMAC) e por ID de contato; a revisão mostra os totais exatos (brutos, únicos, duplicados, sem telefone, telefone inválido).
- Novas tabelas `transmission_batches` e `transmission_recipients` (telefone criptografado, `unique(batch_id, phone_hash)`).
- Um job curto `SendTransmissionRecipient` por destinatário: ordem embaralhada, intervalo de 45 a 90 s, `tries=1`, claim atômico com `lockForUpdate`. Status: aguardando / enviando / aceita pelo Chatwoot / falhou / sem confirmação. Nada é reenviado automaticamente.
- Áudio (mp3, m4a, aac, wav, ogg, oga, opus) até 12 MB; validação por MIME, extensão e conteúdo real (finfo + bloqueio de cabeçalhos MZ/ELF).
- A mídia fica no disco privado até o último destinatário terminar e depois é apagada (`media_removed_at`).
- Card "Último lote" com atualização a cada 20 s enquanto o lote roda.
- Removidos a etiqueta padrão "parceiros" e o job longo antigo (`SendBroadcastMessage` ficou sem uso).
- Testes: BroadcastReviewTest reescrito, TransmissionQueueTest novo, ListsPaginationTest ajustado. Suíte: 536 passando.
- Backups: `storage/backups/broadcasts.pre_fase2` e `storage/backups/build.pre_fase2`.


## 2026-11-06 — Lançamentos: "Vínculos e comprovante" recolhido por padrão

- No formulário de receita/despesa, a seção opcional agora fica oculta atrás de um link pequeno "Adicionar vínculos ou comprovante (opcional)"; ao abrir, o link vira "Ocultar vínculos e comprovante".
- Estado no servidor (`showOptionalLinks`); valores preenchidos não são apagados ao recolher, e o link mostra "N preenchido(s)".
- Abre automaticamente ao editar lançamento que já tem vínculo ou comprovante, e quando há erro de validação nesses campos. Novo lançamento/cancelar volta a recolher.
- Botão acessível (`type=button`, `aria-expanded`). Teste novo: OptionalLinksCollapseTest; Etapa2RateiosVinculosTest ajustado para abrir a seção.
- Backup: `storage/backups/transactions.pre_collapse`.

## 2026-11-06 — Importação XP (somente JCP BBDC4 novo)
- Origem: extrato XLSX XP Conta 2858237 (sha256 c4ae11e6278fde75fb62644739a99ee2dc583a22da9d468fc86e3257061a88c2), período 01/01–06/11/2026.
- XLSX bruto não mapeia no parser genérico (cabeçalho fora da 1ª linha); lote via CSV normalizado derivado (48 linhas).
- Batch broker_statement_imports #10, broker=XP, user=1 (cassio@motaesilva.adv.br), confirmado.
- Status: imported=1 (JCP BBDC4 03/11/2026 qtd 4045 líquido R$ 63,32), duplicate_not_imported=40, pending_manual=7 (TED/transf. caixa).
- asset_income_events: 2013 → 2014; soma líquida 399451.93 → 399515.25. Financeiro (transactions) e posições não alterados.
- IR não informado no extrato; bruto não inventado. Sem preço médio.


## 2026-11-06 — Repasse: "Tipo de conta" antes de "Pagar pela conta"

- No modal de registrar repasse (`resources/views/livewire/banking/accounts/repasses.blade.php`), novo seletor "Tipo de conta" (Todos os tipos, Corrente, Poupança, Investimento, Fundo, Dinheiro) que filtra as contas ativas do campo "Pagar pela conta".
- Contas exibidas como "Nome · Tipo" (`BankAccount::pickerOptions`). Padrão "Todos os tipos" (não bloqueia o fluxo atual); a conta pré-selecionada é mantida; trocar o tipo só limpa a conta se ela for de outro tipo. Tipo inválido volta para todos.
- Validação e cálculo do pagamento inalterados. Teste novo: RepasseBankTypeFilterTest. Backup: `storage/backups/repasses.pre_tipo`.


## 2026-11-06 — Número de processo sempre no padrão CNJ (máscara + histórico)

- `App\Support\ProcessNumber::format`: só formata texto com exatamente 20 dígitos e separadores comuns para `NNNNNNN-DD.AAAA.J.TR.OOOO`; texto livre ou com outra quantidade de dígitos fica como está (nunca completa com zeros).
- Trait `NormalizesProcessNumbers` (evento `saving`) em Writ, Transaction (link_process), ProcessReceipt, BrokerCommission, BrokerAdvance, BrokerProcessExpense (process_ref) e Receivable (process_reference).
- Máscara `x-process-number` aplicada também em: Lançamentos (vínculo), Processar recebimento, Comissões, Despesas de processo e Recebíveis. A diretiva agora roda antes do wire:model (captura), funciona ao colar e não mexe em texto com letras.
- Comando `php artisan process-numbers:normalize [--confirm]` (prévia por padrão; backup JSON em `storage/app/backups/`).
- Histórico em produção: 3 registros formatados — writs #45, transactions #310 e process_receipts #13 (os dois últimos eram 01369335520264019198 → 0136933-55.2026.4.01.9198). Demais tabelas sem números a ajustar.
- Teste novo: ProcessNumberTest. Backup de código: `storage/backups/pre_pn/`.


## 2026-11-06 — Transmissão fase 3: histórico, pausar/retomar/cancelar e reenviar só falhas

- Abas "Nova transmissão" e "Histórico" em /broadcasts. Novo componente `resources/views/livewire/broadcasts/history.blade.php`.
- Histórico: só as transmissões do próprio usuário, 10 por página, mais recentes primeiro; autor, data/hora (Fortaleza), texto, etiquetas, tipo e nome do anexo, contagens por situação (uma consulta agrupada). Detalhes com destinatários 20 por página, busca por nome e filtro por situação; telefone exibido só com os 4 últimos dígitos. Aviso: o histórico começa nesta atualização; envios antigos não aparecem.
- Pausar/Retomar/Cancelar (somente o dono; lote de outra pessoa = 404). A tarefa de cada pessoa trava o lote antes de reservar o envio: pausada/cancelada não envia. Retomar reagenda só os pendentes (5 s + 45–90 s entre cada) com novo token; tarefas antigas perdem a validade. Cancelar pede confirmação ("Mensagens já aceitas não podem ser retiradas."), marca pendentes como "Cancelado" e mantém as aceitas.
- "Reenviar somente falhas": cria nova transmissão ligada à original (`retry_of_id`), mesmo texto/etiquetas/anexo copiado, só com destinatários "Falhou"; "Sem confirmação" nunca entra. Uma vez por lote. Original não é alterado. Anexo agora é guardado enquanto houver falhas; se o anexo original sumiu, o reenvio é bloqueado com aviso (não envia sem anexo).
- Migração `2026_11_06_500000_transmission_history_controls` (retry_of_id, paused_at, cancelled_at, controlled_by, dispatch_token). Teste novo: TransmissionHistoryTest (8). Suíte: 552 passando. Backup: `storage/backups/pre_t3/`.


## 2026-11-06 — Transmissão fase 4: lista "Não receber", aviso de saída e agendamento

- Lista "Não receber" (`transmission_suppressions`, aba nova em /broadcasts): telefone criptografado + hash HMAC (mesmo padrão E.164 dos destinatários), nome/motivo opcionais, origem "manual", data; "Voltar a receber" só por clique explícito (registro mantido). Vale para o sistema todo. Telefone exibido só com 4 últimos dígitos.
- A lista é aplicada na revisão (contagem "Na lista Não receber (fora)"), na criação do lote (`suppressed_omitted`), no reenvio de falhas e de novo na tarefa de cada pessoa antes de chamar o Chatwoot (status "Não recebe (lista de saída)", sem chamada externa).
- `TransmissionSuppression::classify()` reconhece SAIR / quero sair / não quero mais receber, mas NÃO está ligado a nenhum webhook: o sistema não lê respostas nem responde sozinho.
- Aviso opcional (ligado por padrão) no fim da mensagem: "Se não quiser mais receber estas mensagens, responda SAIR." — mesma função da prévia, nunca duplicado.
- "Quando enviar": Agora (padrão, igual antes) / Em uma data e horário / Toda semana (alternando). Horário de Fortaleza (UTC−3), 08:00–20:00, mínimo 5 min de antecedência. Semanal começa seg/ter/qua, manhã 09:00 ou tarde 14:00; depois uma vez por semana alternando seg→ter→qua e manhã↔tarde. Sempre com revisão e confirmação.
- `transmission_schedules` + comando `transmissions:dispatch-scheduled` (agendador, a cada minuto, withoutOverlapping). Reserva a ocorrência com trava antes de ler os contatos (idempotente; uma por semana), relê as etiquetas no Chatwoot, aplica deduplicação e "Não receber" e cria o lote com o mesmo fluxo de envio (45–90 s por pessoa). Falha ao ler contatos: registra o motivo e para (semanal fica pausado, data única "Não executada"); nunca tenta sozinho de novo. Retomar vai para a próxima data futura (sem envio atrasado).
- Histórico mostra agendamentos do dono com Pausar/Retomar (semanal) e Cancelar agendamento.
- Teste novo: TransmissionOptOutScheduleTest (12). Backup de código: `storage/backups/pre_t4/`.


## 2026-11-06 — Usuários e acessos: convite por link, módulos por usuário e bloqueio

- Colunas novas em `users`: `is_admin`, `status` (active/blocked), `modules` (JSON; vazio = nenhum módulo), `blocked_at`. Backfill: todos os usuários existentes ficaram com os 8 módulos; só o id 1 é administrador. Senhas e demais dados não foram alterados. Esses campos não são preenchíveis em massa.
- Módulos: Dashboard, Financeiro, Investimentos (inclui Bens), Requisitório, Corretores, Contatos, Sociedade, Transmissão. Configurações pessoais, perfil e logout ficam livres; "Usuários e acessos" só para administrador.
- Controle em três camadas (`App\Support\Access`): menu filtrado; middleware `EnsureModuleAccess` no grupo web (rota de módulo não liberado = 403; conta bloqueada é desconectada na próxima requisição); hook Livewire `ModuleAccessHook` (montagem e cada atualização de componente, inclusive componentes embutidos; exceção explícita: `banking.regime-switch` também para quem tem Dashboard). Login de conta bloqueada é recusado.
- Página Configurações → Usuários e acessos (`/configuracoes/usuarios`): convidar (nome, e-mail, módulos), lista de usuários com módulos editáveis, bloquear/reativar (reativar mantém os módulos), convites com status Pendente/Aceito/Revogado/Expirado, revogar e gerar novo link (o anterior deixa de valer). O administrador não pode se bloquear nem alterar os próprios módulos.
- Convite: token aleatório de 64 caracteres, só o hash HMAC-SHA256 fica salvo; link exibido apenas no momento de gerar; uso único; validade 7 dias; um convite pendente por e-mail. Aceite público em `/convite/{token}` (throttle): e-mail exibido e imutável, senha mínima de 12 caracteres com confirmação; recusado se já existir conta com o e-mail. Depois vai para o login.
- Nada é enviado por e-mail (o link é copiado manualmente). Pendências para depois: servidor de e-mail próprio (SMTP) para envio automático e login com Google (precisa de credenciais OAuth próprias). Nenhum botão simulado foi criado.
- Testes: AccessControlTest (8); BrokerOperationsTest, BrokerReportFiltersTest e PartnershipFlowTest passaram a autenticar um usuário (antes testavam componentes como visitante). Suíte: 572 passando. Backup de código: `storage/backups/pre_t5/`.

- Commit da entrega acima: `f027d58`. Durante essa implantação houve um erro temporário (código publicado antes da migração e um erro de sintaxe no menu), corrigido em poucos minutos com a migração e o ajuste do menu. Prática adotada: rodar migração e conferir a sintaxe das telas imediatamente ao publicar código novo.
- Correção (2026-11-06): o hook Livewire agora também confere conta bloqueada em componentes livres (perfil, configurações): desconecta, invalida a sessão e responde 403. Testes novos em AccessControlTest (bloqueado com tela aberta; administrador não pode ser bloqueado).

## 2026-11-06 — Login com Google (somente usuários já cadastrados)

- Botão "Continuar com Google" no login (aparece só se o cliente OAuth estiver configurado); e-mail e senha continuam funcionando.
- Rotas `GET /login/google` e `GET /login/google/callback` (guest, throttle 10/min). Callback a registrar no Google Cloud Console: `https://app.cassiomota.com/login/google/callback`.
- OAuth com escopos mínimos `openid email profile`, `state` aleatório de uso único guardado na sessão (expira em 10 min) e PKCE S256. ID token verificado pela biblioteca do Google (assinatura, emissor, validade, `aud` = client_id); exige `email_verified`.
- Só entra quem já existe, está ativo e tem o mesmo e-mail (comparação sem maiúsculas). Nunca cria conta. Bloqueado é recusado. No primeiro login a identidade Google (`sub`) é vinculada ao usuário (coluna nova `users.google_sub`, única, oculta); depois disso outra identidade para o mesmo usuário, ou a mesma identidade para outro usuário, é recusada.
- Nenhum token é guardado nem registrado em log; mensagens de erro públicas são genéricas.
- Configuração: `GOOGLE_AUTH_CLIENT_ID/SECRET/REDIRECT_URI` opcionais; sem eles reaproveita o cliente OAuth da Agenda. Agenda, permissões e senhas não foram alterados.
- Envio de convite por e-mail continua pendente: o sistema está com mailer `log` e sem SMTP próprio; o convite segue com link copiado manualmente até haver provedor definido.
- Testes: `GoogleLoginTest` (11) com provedor falso, sem rede. Migração rodada antes de validar; lint de todos os arquivos.


## 2026-11-06 — Convites por e-mail: remetente Google SMTP (preparação) + envio explícito na fila

- Em Configurações → Usuários e acessos, o administrador **id 1** ganha o bloco "Remetente de e-mail (Google SMTP)": remetente estrito `cassio@cassiomota.com`, host `smtp.gmail.com`, porta 587 TLS.
- Senha de aplicativo: gravada **somente** pelo formulário HTTP da própria tela (`name=smtpPassword` / `wire:model="smtpPassword"` / `data-smtp-password`), com cast `encrypted` (APP_KEY). Salvar com campo em branco **não** apaga a senha já existente; remoção só pelo botão explícito "Remover senha". A UI mostra se há senha gravada, nunca o valor nem o comprimento.
- Dá para salvar remetente/host sem senha; "pronto para enviar" só com senha. Configurar ≠ teste de entrega.
- Criar ou regenerar convite **não** envia e-mail. Envio só no botão **"Enviar por e-mail"** (no link gerado ou na linha do convite), com auth admin. Job `SendUserInvitationEmail` na fila Redis já existente (`queue:work` no container `cassio_worker`); claim atômico (`email_delivery_status` + `email_dispatch_token`) evita duplicata enquanto `queued`; retry após `failed` (tries=3, backoff). Status `pending|queued|sent|failed` com timestamps e erro genérico; convite **não** é aceito pelo envio; link manual permanece.
- Override SMTP só no runtime do envio (`mail.mailers.invitation_smtp`), timeout 15s, From fixo no remetente registrado; `MAIL_MAILER` global permanece (hoje `log` em produção — não tratado como pronto). Autoteste separado: "Testar remetente (só para mim)" envia apenas para o próprio remetente.
- Migração `2026_11_06_900000_invitation_mail_sender`. Testes: `InvitationMailTest`. Backup: `storage/backups/pre_invite_mail/`.
- **Ainda sem senha real nesta etapa** (Google App Password indisponível no momento). Nenhum e-mail externo foi enviado; segredo não foi colocado via shell/env. Quando a senha existir, gravar só pela UI e só então usar o botão explícito / autoteste.


## 2026-11-06 — Autoteste SMTP: falha Google 535 sem 500 Livewire

- Causa: o botão "Testar remetente" envia de forma síncrona no request Livewire; `TransportException` (ex.: Google 535 BadCredentials) subia sem captura e virava HTTP 500.
- `InvitationMailService::sendSelfTest` agora captura qualquer falha de transporte, classifica (auth vs transitória), grava `last_self_test_status/at/error` com mensagem **segura para o operador** (sem username cru nem texto Gmail) e devolve `ValidationException` em `smtpTest` — a UI mostra o alerta e lembra o link manual.
- Job de convite: falha de **autenticação** marca `email_delivery_status=failed` e **não** relança (sem retry inútil); falha transitória marca failed e relança para as tries da fila. Log só com `kind` + classe da exception (sem mensagem do provedor).
- Configuração com senha continua "pronta" para tentar envio; o último teste falho fica visível e **não** finge entrega confirmada. Migração `2026_11_06_910000_mail_sender_self_test_status`.
- Testes novos em `InvitationMailTest` (mock de transporte; sem SMTP real). Nenhum reteste real ao Google nesta correção.


## 2026-11-06 — Diagnóstico SMTP 535: segredo gravado fora do formato Google + validação

- Investigação (sem reteste SMTP, sem exibir segredo): código novo em host/app/worker (mesmo md5); `sendSelfTest` na linha ~335 (não 277); `MAIL_USERNAME`/`MAIL_PASSWORD` ausentes; mailer default continua `log`; override só `invitation_smtp` em runtime; scheme Laravel 12 = `smtp` (STARTTLS) na porta 587; conexão autentica no Google (535), então host/porta/TLS ok.
- Meta do segredo no DB (só booleanos/comprimento): **não** é senha de aplicativo Google — comprimento ≠ 16 letras a–z (havia mistura de maiúsculas/dígitos/outros). Isso basta para explicar BadCredentials com 2SV e app passwords geradas: o valor persistido não era o formato esperado.
- Correção: ao salvar, remove espaços ASCII dos grupos e exige `^[a-z]{16}$`; UI mostra aviso `data-smtp-format-bad` se a senha já gravada estiver fora do formato (sem mostrar comprimento/valor). Log de produção: e-mail do remetente e dumps longos de BadCredentials redigidos; views cache limpo.
- Sem envio real nesta correção. Próximo teste só após o operador colar de novo a senha de aplicativo (16 letras) e salvar.

## 2026-11-06 — Convites: Gmail OAuth (gmail.send) no lugar de senha de aplicativo

- Transporte preferido: **google-oauth** via Gmail API `users/me/messages/send`. Sem fallback silencioso para SMTP/senha de app (legado permanece no banco, UI recolhida, não usado automaticamente).
- OAuth isolado da Agenda e do login: callback próprio `https://app.cassiomota.com/configuracoes/email/google/callback`, `include_granted_scopes=false`, PKCE S256, `prompt=consent`, `access_type=offline`, state de uso único na sessão.
- Escopos: `openid` + `email` (verificar conta) + `https://www.googleapis.com/auth/gmail.send`. Conta deve ser exatamente `cassio@cassiomota.com`. Tokens em `gmail_send_credentials` (encrypted). Desconectar apaga tokens locais (sem revoke automático no Google).
- Reaproveita client_id/secret da Agenda via `config/gmail-send.php` (sem imprimir segredos). Calendar scopes inalterados.
- UI: “Conectar Gmail somente para envio de convites”; teste explícito; enviar convite na fila Redis. Conectar ≠ enviar.
- Testes: `GmailSendOAuthTest` + `InvitationMailTest` com `FakeGmailSendClient` (sem rede). Registrar callback no Console e ativar Gmail API antes do connect real.

## 2026-11-06 — Transmissão: webhook Chatwoot para SAIR (opt-out automático)

- Endpoint `POST /webhooks/chatwoot` (throttle 60/min, sem CSRF/sessão). Autentica com `CHATWOOT_WEBHOOK_TOKEN` via header `X-Chatwoot-Webhook-Token` (ou `X-Webhook-Token`) ou query `token`. Token vazio = **desligado** (503). Token inválido = 401.
- Só processa `message_created` **incoming** e **não privada**. Outgoing/private/outros eventos → 200 `ignored` (Chatwoot não reenvia).
- Texto classificado por `TransmissionSuppression::classify()` (SAIR / quero sair / não quero mais receber / parar / stop / cancelar e variações com acento/pontuação). Frases que só **contêm** a palavra não bloqueiam.
- Telefone normalizado E.164 (mesmo helper da Transmissão); entra na lista "Não receber" com origem `chatwoot`, motivo e data. Idempotente. **Não** envia resposta no WhatsApp nem altera a conversa no Chatwoot.
- Proteção anti-bloqueio em massa: payload com vários itens é ignorado; no máx. `CHATWOOT_WEBHOOK_OPTOUT_PER_MINUTE` (padrão 30) opt-outs reais por minuto (429).
- Testes: `ChatwootOptOutWebhookTest`. Nenhuma migration. Webhook **não** foi criado no Chatwoot nesta etapa — falta configurar token no `.env` e o webhook na UI do Chatwoot.

## 2026-11-06 — Webhook SAIR: restrição fail-closed à inbox 15 (CMS-6000)

- `CHATWOOT_OPT_OUT_INBOX_ID=15` (conta 1 / CMS-6000). Eventos de outras inboxes ou sem `inbox_id` no payload são ignorados (`wrong_inbox`); sem ID configurado também não bloqueia ninguém.
- Nenhuma alteração no Chatwoot nesta etapa.


## 2026-11-06 — Webhook SAIR: assinatura oficial X-Chatwoot-Signature (OR com token)

- Auth OR no mesmo `POST /webhooks/chatwoot`:
  1. Assinatura oficial: headers `X-Chatwoot-Signature` + `X-Chatwoot-Timestamp`; `CHATWOOT_WEBHOOK_SECRET` = Secret do webhook **Transmissão - SAIR** na UI. Fórmula sha256=HMAC-SHA256(secret, "{timestamp}.{raw_body}") (raw body, sem re-serializar); tolerância `CHATWOOT_WEBHOOK_SIGNATURE_TOLERANCE` (padrão 300s). Helper `App\Support\ChatwootWebhookSignature`.
  2. Token legado: `CHATWOOT_WEBHOOK_TOKEN` (header/query) — preservado; não removido.
- Sem token **e** sem secret → 503 `webhook_auth_unset`. Secret vazio = assinatura desligada (token continua válido).
- Inbox fail-closed 15 e demais regras de SAIR inalteradas. **Não** gravamos `CHATWOOT_WEBHOOK_SECRET` nesta etapa (colar depois via clipboard no `.env` + `config:cache`). Token tmp e `CHATWOOT_WEBHOOK_TOKEN` mantidos. Sem ação no Chatwoot/nginx_msa.

## 07/11/2026 — Máscara de valores em reais (padrão brasileiro)

- `x-money` reescrito (`resources/js/money-mask.js`): digitação livre com vírgula (o ponto do teclado vira vírgula), milhar com ponto, até 2 casas; colar `1234,56`, `1.234,56`, `R$ 1.234,56`, `1234.56` funciona. Ao sair do campo mostra `1.234,56`; o Livewire recebe `1234.56` (vazio = vazio). `x-money.signed` aceita negativo.
- Novo `App\Support\Money::normalize()` no backend.
- Campos que passaram a usar a máscara: Bens (aquisição, atual, venda), Despesas de processos, Pagamento de fatura, Rateio por valor nos lançamentos, Valor fixo de corretor (lançamento, comissão e recebimento de processo). Saldo inicial e saldo do extrato aceitam negativo.
- Testes: `tests/Unit/MoneyTest.php`, `tests/Feature/MoneyInputsTest.php`, `tests/js/money-mask.test.mjs`. Build anterior: `/root/app-cassio-backups/mascaras-public-build-antes-20261107`.

## 07/11/2026 — Módulo Divisões (estilo Splitwise)

- Novo módulo `divisoes` (menu "Divisões", logo depois de Sociedade). Rotas `/divisoes` e `/divisoes/{grupo}`.
- Grupos (Sociedade, Família, Amigos) com participantes. O Cassio cria direto; participante só solicita, e o grupo fica "Aguardando aprovação".
- Convite por link copiado (mesmo sistema da Transmissão, `UserAccessService::invite`) liberando só o módulo Divisões; nenhum e-mail é enviado. Participante vê só os grupos dele e não acessa o Financeiro (403).
- Despesas: quem pagou, valor (máscara R$), data, categoria, comprovante opcional (recolhido), divisão igual / percentual / valor / cotas com conferência da soma, parcelamento (1/5…). Configuração por grupo: despesas de participantes precisam ou não de aprovação.
- Saldos por grupo, dívidas simplificadas (menor número de pagamentos) e saldo geral por pessoa.
- Acertos: quando o Cassio participa, escolhe a conta e é criado o lançamento no Financeiro (`source_type = division_settlement`). Despesa paga pelo Cassio pode ser vinculada a uma saída existente (conta ou cartão), sem lançar duas vezes; o mesmo lançamento não pode ser vinculado duas vezes.
- Histórico (auditoria) por despesa e extrato do grupo com saldo acumulado por pessoa.
- Tabelas novas: `division_groups`, `division_members`, `division_expenses`, `division_expense_shares`, `division_settlements`, `division_audits`. Nenhuma tabela existente foi alterada.
- Backup do banco antes da migration: `/root/app-cassio-backups/divisoes-db-antes-20261107.json.gz`.
- Testes: `tests/Feature/Divisions/DivisionsTest.php`.

## 07/11/2026 — Divisões em Configurações › Usuários

- Novo bloco "Divisões · pessoas, convites e grupos" em Configurações › Usuários: lista quem foi convidado (inclusive pelos convites feitos dentro do módulo), o status (convite pendente, aceito, revogado ou expirado; conta bloqueada), os grupos de cada pessoa, adicionar/remover de grupos, gerar novo link e revogar acesso.
- "Convidar pessoa": ao marcar Divisões, dá para escolher já os grupos em que a pessoa entra.
- Um único registro de convite por pessoa: o botão do módulo e Configurações usam `DivisionService::divisionLinkFor()`, que gera novo link no MESMO convite (o link anterior deixa de valer). Nenhum e-mail é enviado.
- Revogar acesso: revoga convites pendentes e tira o módulo Divisões; se a conta só tinha Divisões, ela é bloqueada. O histórico dos grupos continua.
- Remover de um grupo só é permitido se a pessoa não tem despesa, parte ou acerto nele (não apaga histórico).
- Testes: `tests/Feature/Divisions/DivisionsAccessTest.php`. Nenhum dado real foi alterado.

## 07/11/2026 — Link atual do convite abaixo do nome

- Configurações › Usuários (lista de convites e bloco Divisões) e Divisões › Participantes mostram, em letra pequena abaixo do nome, o link de convite válido no momento, com botão Copiar ("Link copiado de <nome>").
- Sem link válido: "Sem link gerado" (ou "Gere um novo link" para convites antigos que só têm o hash) com o botão Gerar link. Revogado, expirado ou aceito nunca aparece como válido.
- Nova coluna `user_invitations.token_encrypted` (Crypt do Laravel), gravada em convites novos e links regenerados; é apagada ao revogar ou aceitar. O link só aparece se o token decifrado confere com o hash.
- Nenhum link foi gerado automaticamente e nenhum dado real foi alterado. Backup da tabela antes da migration: `/root/app-cassio-backups/user_invitations-antes-link-20261107.json`.
- Testes: `tests/Feature/Divisions/InviteLinkDisplayTest.php`.

## 07/11/2026 — Novo nome e marca: Vértice CM

- Sistema renomeado de "Cassio Finance" para **Vértice CM** (APP_NAME no .env e .env.example, título das páginas, topo, menu lateral, login, dashboard, PDFs, README, docs e scripts).
- Marca nova (SVG original): vértice/pico com "CM" dentro, estrela de energia e gradiente âmbar → laranja (#FF6F00, cor principal do tema) → vermelho. Versões: ícone (`public/brand/vertice-cm-icone.svg`) e horizontal com texto (`public/brand/vertice-cm-horizontal.svg`), mais as animadas (`*-animado.svg`).
- Animação em CSS dentro do SVG: o pico se desenha (~1,3 s), o "CM" aparece, a estrela pulsa e um brilho passa a cada 6 s. Com "reduzir movimento" ligado, fica parada. Usada no login e no topo/menu (`<x-vertice-logo>`).
- Favicon estático: `favicon.svg`, `favicon.ico`, PNG 16/32, apple-touch 180, 192/512 no `site.webmanifest` (PWA "Vértice CM", cor #FF6F00).
- Logo personalizado (Configurações) continua tendo prioridade no topo; não havia logo padrão gravado para trocar. Backup do favicon anterior: `/root/app-cassio-backups/logo-antes-20261107/`.
- Teste: `tests/Feature/BrandVerticeTest.php`.

## 2026-11-07 — Divisões: remover/desativar participante, extrato em PDF e decimais BR

- **Participantes (Divisões):** o admin pode remover alguém do grupo pela tela do grupo, mas só quando a pessoa não tem despesas, partes ou acertos ali. Se tiver, a tela mostra o motivo e oferece **"Desativar participante"**. O desativado some de "quem pagou" e da divisão das novas despesas, mas saldos, extrato e histórico continuam. Dá para reativar. Tudo fica na auditoria. Nova coluna `division_members.active`, com backup antes em `/root/app-cassio-backups/divisoes-db-antes-membros-20261107.json.gz`.
- **Extrato em PDF:** a rota `divisoes/{grupo}/extrato.pdf?de=&ate=` gera o PDF no mesmo estilo dos PDFs de requisitório e corretores, com a logo Vértice CM. Ele traz o período, o saldo anterior, os movimentos com saldo acumulado por pessoa, o saldo final e "quem paga quem". O participante só baixa o PDF dos próprios grupos.
- **Decimais BR:** a nova diretiva `x-decimal="N"` (resources/js/decimal-mask.js) aceita vírgula ao digitar e ao colar, e mostra as casas decimais. Ela vale para participação %, percentual aplicado, honorários/escritório %, rateio %, juros/multa % a.m., quantidades e valor unitário (6 casas), percentuais de corretores e divisão por %/cotas. No backend, `Money::decimal()` normaliza os valores antes de salvar.
- Testes: tests/Feature/Divisions/MembersPdfDecimalTest.php e tests/js/decimal-mask.test.mjs. Suíte completa: 700 testes passando.

## 2026-11-07 — Divisões: celular do participante e botão "Cobrar" (WhatsApp)

- **Celular (opcional):** é um campo novo `division_members.phone`, guardado em E.164 (+55…). Ele aparece no cadastro de participante do grupo, na edição direta na lista ("Adicionar/Editar celular") e no bloco Divisões de Configurações › Usuários ("Salvar celular"). A máscara é (99) 99999-9999 e a validação é a mesma de Contatos (`BrazilianDocuments::isValidPhone`). O participante pode editar o próprio número. A mesma pessoa (mesmo e-mail) fica com o mesmo número em todos os grupos. Backup antes da migração: `/root/app-cassio-backups/divisoes-db-antes-celular-20261107.json.gz`.
- **Cobrar:** só o admin vê o botão. Ele aparece em cada linha de "quem paga quem" em que alguém deve ao admin e por pessoa no "Saldo geral por pessoa". O botão abre um modal com uma mensagem educada e editável (nome, valor, grupo e link da página do grupo). "Abrir WhatsApp" abre `wa.me/<número>?text=…` numa nova aba, e o sistema não envia nada. Sem celular, o botão fica desabilitado com a dica "Cadastre o celular". Cada abertura registra "Cobrança aberta", com valor e data, no novo quadro "Histórico do grupo".
- Testes: tests/Feature/Divisions/PhoneChargeTest.php.

## 2026-11-07 — Testes isolados do banco de produção, "Complete seu cadastro" e limpeza de convites

- **Causa:** o `phpunit.xml` já pedia SQLite em memória, mas, com a configuração em cache (`php artisan optimize`, `bootstrap/cache/config.php`), o Laravel ignorava essas variáveis e os testes conectavam no MySQL de produção (Umbler). Uma sonda confirmou `env=production conn=mysql db=app_cassio`. O `RefreshDatabase` não conseguiu rodar `migrate:fresh` em produção, porque o comando pede confirmação e foi cancelado. Cada teste rodou dentro de uma transação desfeita no final (rollback), e por isso nenhum dado ficou gravado ou foi apagado. Mesmo assim, os contadores de ID (AUTO_INCREMENT) avançaram: users 3→1387 e user_invitations 1→14, entre outros.
- **Evidência de que os dados estão íntegros:**
  - a tabela `migrations` tem os 64 lotes com o histórico incremental (um `migrate:fresh` teria deixado tudo no lote 1);
  - as contagens de todas as tabelas de negócio são iguais às do backup completo de 07/11 às 11:52 (UTC). Exemplos: transactions 320, b3_position_snapshots 31, asset_income_events 2014, contacts 60, writs 36, users 3;
  - não ficou nenhuma linha órfã: transmission_suppressions tem 0 linhas.
- **Correção:**
  - no `phpunit.xml`, as variáveis de banco agora são forçadas (`force="true"`), e `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` e `APP_EVENTS_CACHE` apontam para arquivos inexistentes, para que os testes ignorem os caches de produção;
  - a nova trava `tests/TestDatabaseGuard.php`, chamada em `Tests\TestCase::createApplication`, aborta a suíte se o banco não for SQLite em memória, se APP_ENV não for `testing` ou se a configuração estiver em cache;
  - testes novos: `tests/Unit/TestDatabaseGuardTest.php` e `tests/Feature/TestDatabaseIsolationTest.php`;
  - a suíte completa rodou com o cache de produção ativo, e as contagens e o AUTO_INCREMENT de produção ficaram iguais antes e depois.
- **Complete seu cadastro:**
  - novas colunas `users.contact_id` e `users.profile_completed_at`;
  - nova tela `/completar-cadastro` (Volt `profile.complete`) com os campos de pessoa de Contatos: nome, e-mail (só leitura), celular, CPF validado, nascimento, CEP com busca, rua, número, complemento, bairro, cidade, UF e Pix;
  - o middleware `EnsureProfileCompleted` redireciona os convidados (não admin) até o cadastro ser concluído; login, logout, convite e Livewire ficam liberados;
  - o `ProfileCompletionService` cria ou atualiza o Contato (busca por e-mail e depois por CPF, sem duplicar), adiciona o papel `pessoal` (convidado só de Corretores fica com `corretor`), liga o Contato ao usuário e sincroniza o celular com os participantes de Divisões. Quando o celular é alterado em Divisões, o Contato ligado também é atualizado;
  - Mateus e Heleno (sem `profile_completed_at`) veem a tela no próximo acesso;
  - testes: `tests/Feature/ProfileCompletionAndInvitesTest.php`.
- **Convites:**
  - revogar um convite não aceito agora apaga o registro, e o mesmo vale para os convites anteriores substituídos por um novo;
  - as listas de Configurações › Usuários e de Divisões mostram só convites aceitos ou pendentes ainda válidos (`UserInvitation::visible()`).
- **Limpeza autorizada pelo Cassio** (backup: `/root/app-cassio-backups/convites-revogados-antes-limpeza-20261107.json`):
  - apagados os convites 14 e 15 ("Heleno Mota" <helenomota@gmailc.om>, revogados e sem conta);
  - removido o participante 2 ("Heleno Mota" <helenomota@gmailc.om>) do grupo "SHC - 3 irmãos": não tinha despesas, partes nem acertos, e a remoção ficou registrada na auditoria;
  - o Heleno real (helenomota@gmail.com, usuário 1387) e o Sandro (convite 16 pendente, participante 3) não foram mexidos.

## 2026-11-07 — Divisões: celular do participante preenchido a partir do Contato

- `DivisionService::fillPhonesFromContacts()` preenche o celular do participante com o do Contato do usuário ligado (`users.contact_id`), só quando o participante está sem número. Número digitado à mão nunca é sobrescrito. O preenchimento acontece ao adicionar a pessoa ao grupo, ao vincular a conta (claimMemberships/aceite) e ao concluir ou atualizar o "Complete seu cadastro". Este último agora também liga os participantes do mesmo e-mail à conta e só preenche os números vazios.
- Backfill: o comando `php artisan divisions:fill-phones` (com `--dry-run` para só listar). Execução em produção: 1 participante preenchido, Heleno Mota (participante 4, "SHC - 3 irmãos"), com o celular do contato 447. Sandro já tinha número e ficou igual. Backup: `/root/app-cassio-backups/divisoes-antes-celular-contato-20261107.json`.
- Testes: tests/Feature/Divisions/PhoneFromContactTest.php. Suíte completa: 716 testes passando, no banco isolado.

## 2026-11-07 — Investimentos › Posições: preço com 4 casas no padrão BR

- Preço médio e cotação aparecem com 4 casas (R$ 12,3456); totais, valor de mercado e resultado continuam com 2.
- O campo "Preço por unidade" do modal de cotação passou a ser texto com a máscara `x-decimal.pad="4"`: aceita vírgula ao digitar e ao colar e mostra sempre 4 casas. O saldo de liquidez automática usa 2 casas. O modificador `.pad` é novo na diretiva `x-decimal`.
- Backend: `Money::decimal()` normaliza o valor antes de validar e salvar ("12,3456" → 12.3456).
- Teste: tests/Feature/Investments/PositionPriceFourDecimalsTest.php. Suíte completa: 717 testes passando, no banco isolado.

## 2026-11-07 — Divisões: adicionar pessoa já cadastrada ao grupo

- Na página do grupo, no quadro "Participantes" (só admin), há o novo bloco "Adicionar pessoa já cadastrada": uma busca por nome, e-mail ou celular em usuários com conta, participantes de outros grupos e Contatos. Cada pessoa aparece uma vez, e quem já está no grupo não aparece. O botão "Adicionar" coloca a pessoa no grupo:
  - quem já tem conta fica ligado à conta, sem convite novo; se a conta não tinha o módulo Divisões, ele é liberado e os outros módulos continuam;
  - o celular vem de outro grupo ou do Contato;
  - a mesma pessoa não entra duas vezes no mesmo grupo (mesmo e-mail ou mesma conta).
- Logo abaixo continua o formulário "Adicionar novo participante", para quem ainda não está no sistema.
- `DivisionService::searchExistingPeople()` e `addExistingPerson()`. Backup antes: `/root/app-cassio-backups/divisoes-antes-adicionar-existente-20261107.json`. Nenhum participante foi adicionado em produção.
- Teste: tests/Feature/Divisions/AddExistingPersonTest.php. Suíte completa: 718 testes passando, no banco isolado.

## 2026-11-07 — Lançamento de cartão: "Salvar e novo" mantém cartão e fatura

- O modal de despesa no cartão já tinha Cancelar, Salvar e "Salvar e novo" (commit b87a8ac), mas "Salvar e novo" limpava também o cartão e a fatura. Agora, no lançamento de cartão, ele salva, limpa valor, descrição, categoria, observações e vínculos, e **mantém cartão, fatura e data** para a próxima compra da série. Lançamentos de conta seguem como antes.
- Teste atualizado: tests/Feature/Banking/TransactionFlowTest.php (a segunda compra cai na mesma fatura sem escolher o cartão de novo). Suíte completa: 718 testes passando, no banco isolado.

## 2026-11-07 — Convites simplificados (e-mail + apelido + celular) e nome real no cadastro

- **Convite** (Configurações › Usuários › Convidar pessoa):
  - **E-mail é obrigatório**; Apelido e Celular são opcionais;
  - sem apelido, a lista mostra a parte do e-mail antes do @;
  - o celular é validado e guardado no convite (nova coluna `user_invitations.phone`, E.164), vai para os grupos marcados e pré-preenche o "Complete seu cadastro".
- **Divisões › grupo › Adicionar novo participante:** E-mail * e Apelido (opcional), além do Celular (opcional).
- **Complete seu cadastro:** obrigatórios: nome completo, celular, data de nascimento, CPF, CEP, rua, número, bairro, cidade e UF. Opcionais: complemento e Pix. Ao concluir, o nome real substitui o apelido no usuário, nos participantes de todos os grupos (mesmo e-mail), no Contato e no convite.
- Backup antes da migração: `/root/app-cassio-backups/user_invitations-antes-celular-20261107.json`.
- Teste: tests/Feature/SimpleInviteNicknameTest.php. Suíte completa: 721 testes passando, no banco isolado.

## 2026-11-10 — Bens: pagamentos (entrada, cartão parcelado e previstos)

- Cada bem ganhou a seção **Pagamentos**: valor total da compra (o antigo "valor de aquisição"), barra "pago X de Y, falta Z", "+ Pagamento" pela conta (vira lançamento no extrato) ou no cartão (compra parcelada nas faturas), e pagamentos **previstos** com o botão "Paguei" (conta ou cartão, data e parcelas escolhidos na hora).
- Lançamentos gerados ficam ligados ao bem (link "Bem: ..." no Financeiro) e só são alterados pelo bem; apagar o pagamento apaga o lançamento e recalcula a fatura.
- Proteções: aviso quando pago + previsto passa do total; aviso de lançamento parecido já existente na conta (extrato importado); a importação de extrato marca "possível duplicata: pagamento de bem"; não deixa excluir bem com pagamento lançado.
- Nova categoria de sistema **Aquisição de bem** (padrão nos pagamentos de bens, pode ser trocada): tira da conta ou entra na fatura, mas fica fora do resultado e dos gráficos de despesas, nos dois regimes.
- Bens antigos continuam iguais. Teste: tests/Feature/PropertyPaymentsTest.php. Backup: /root/app-cassio-backups/bens-pagamentos-20261110/.
