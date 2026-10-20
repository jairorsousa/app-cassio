# Recebimento de processo

O recebimento bancário é dividido em valor do cliente e honorários. Dos honorários saem a comissão do corretor, a comissão do escritório e a receita própria. O controle de despesas e adiantamentos é por corretor: não existe vínculo com cadastro ou número de processo. O resumo do recebimento mostra a composição dos valores, sem estados de pagamento, saldos pendentes ou ações para registrar repasses.

## Uso

1. Cadastre as despesas pagas na aba **Despesas de processos** do corretor, informando data, valor, descrição e conta. Cada cadastro gera uma única saída no financeiro. A aba mostra o valor recuperado, o saldo disponível e o histórico dos descontos.
2. Em uma receita recebida em conta, marque **Recebimento de processo**. A divisão abre após salvar. Para uma entrada já cadastrada ou importada por OFX, use **Recebimento de processo** na listagem, sem duplicar o crédito.
3. Selecione o corretor, informe o nome do cliente e separe os honorários por valor ou percentual da entrada. O restante é o valor bruto do cliente.
4. Distribua os honorários entre corretor e escritório por valor ou percentual **dos honorários**. O saldo é a **Minha receita**, com uma categoria de receita opcional. Quando houver comissão do corretor, selecione o **Tipo de caso** cadastrado, como no lançamento manual. O **Nome da comissão** pode ser informado; se ficar vazio, será usado o nome do cliente. A comissão é criada automaticamente na aba **Comissões** do corretor com o tipo de caso, nome, valor, data e conta do recebimento, sem criar um pagamento ou outra saída bancária.
5. Informe quais despesas daquele corretor deseja recuperar e quanto de cada uma. Elas reduzem o repasse ao cliente, sem aumentar os honorários nem gerar outra entrada bancária.
6. Escolha os adiantamentos a compensar na comissão do corretor. Nenhum adiantamento é aplicado automaticamente às comissões deste novo fluxo, nem ao cadastrar novos adiantamentos depois do recebimento.
7. Confira os valores líquidos e salve. O resumo exibe a parte do cliente, a comissão do corretor, a comissão do escritório e sua receita. O link **Ver corretor** abre o cadastro que contém a comissão criada. O acompanhamento das comissões continua no módulo de corretores.

Exemplo: entrada de R$ 30.000; honorários de R$ 6.000; cliente bruto de R$ 24.000. Comissão de R$ 1.500 ao corretor e R$ 1.500 ao escritório deixam R$ 3.000 de receita própria. Recuperar R$ 500 de despesas deixa R$ 23.500 para o cliente. Compensar R$ 300 de adiantamentos deixa R$ 1.200 a repassar ao corretor.

## Saldos e alterações

- Despesas e adiantamentos podem ser usados parcialmente em vários recebimentos do mesmo corretor. Cada utilização reduz o saldo disponível. Não é possível usar registros de outro corretor, ultrapassar o saldo disponível, o valor do cliente ou a comissão.
- Cálculos e validações usam centavos. No modo percentual, centavos restantes são distribuídos de forma determinística para fechar exatamente os honorários.
- A divisão fica protegida enquanto houver repasses, compensações ou descontos. **Desfazer desconto** libera o saldo da despesa e reabre o valor do cliente. **Desfazer compensação** libera o adiantamento e reabre a comissão. Depois de desfazer os vínculos, é possível editar a divisão.
- Despesas recuperadas e adiantamentos usados neste fluxo ficam protegidos contra edição/exclusão até desfazer os vínculos. Repasses não podem ultrapassar a comissão líquida das compensações escolhidas.
- Pagamentos registrados na versão anterior são preservados no histórico e podem ser desfeitos ou desvinculados para permitir correções. **Desfazer repasse** remove uma saída gerada anteriormente; **Desvincular** preserva uma saída preexistente ou importada. O recebimento não oferece novas ações de pagamento.
- Na listagem, **Excluir** abre uma confirmação única. Excluir o recebimento remove sua divisão, as comissões e os repasses gerados. Libera os descontos e compensações, preservando as despesas e adiantamentos originais. Registros apenas vinculados são preservados. A operação é atômica.

## Relatórios e compatibilidade

O caixa continua mostrando os R$ 30.000 efetivamente recebidos e as saídas reais. O resumo distingue receita própria, valores de terceiros, despesas recuperadas. Na visão por categoria, somente a receita própria entra na categoria escolhida; os demais valores aparecem separadamente como valores para repasse e recuperação de despesas. As despesas de processos também aparecem no extrato do corretor e no total de saídas do período.

Divisões antigas continuam disponíveis com seus valores, comissões e pagamentos. Não são recalculadas nem migradas automaticamente para a nova divisão. Para convertê-las, desfaça os vínculos, abra **Editar divisão** e revise os valores no novo formulário. Comissões anteriores conservam o tipo de caso que já possuíam; o novo seletor permite escolher o tipo correto ao editar uma divisão sem movimentações vinculadas. Entradas sem divisão continuam consideradas receitas próprias no resumo.

## Cadastro unificado de corretores

A seleção do recebimento usa os contatos ativos do tipo corretor, assim como a listagem. Registros financeiros antigos sem contato são vinculados ao contato correspondente ou recebem um contato de corretor, mantendo o mesmo ID financeiro e todo o histórico. O vínculo tenta identificar documento e nome, sem misturar homônimos com documentos diferentes. Dados já preenchidos no contato são preservados.

Contatos removidos, inativos ou de outro tipo não entram na seleção. A preparação do diretório é idempotente, ocorre ao abrir a listagem/formulário e contempla novos contatos que ainda não possuem perfil financeiro. O link do recebimento usa o ID do contato, não o ID do registro financeiro.

## Implantação

Execute as migrações antes de disponibilizar a atualização:

```sh
php artisan migrate --force
```

Com Docker: `docker compose exec app php artisan migrate --force`.

A migração `2026_10_20_000000_add_process_receipts_and_broker_expenses` acrescenta `process_receipts`, `broker_process_expenses`, `process_expense_recoveries` e o indicador de compensação manual nas comissões. Preserva os dados existentes e utiliza as tabelas de divisões/repasses criadas em `2026_10_09_000000_create_receipt_destinations`.

A migração `2026_10_20_010000_link_legacy_brokers_to_contacts` corrige os vínculos dos corretores antigos. Seu rollback preserva os contatos e vínculos criados para não ocultar novamente comissões existentes.
