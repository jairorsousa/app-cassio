# Recebimento de processo

O recebimento bancário é dividido em valor do cliente e honorários. Dos honorários saem a comissão do corretor, a comissão do escritório e a receita própria. O controle de despesas e adiantamentos é por corretor: não existe vínculo com cadastro ou número de processo. O resumo do recebimento mostra a composição dos valores, sem estados de pagamento, saldos pendentes ou ações para registrar repasses.

## Uso

1. Cadastre duas contas ativas do tipo **Repasse**, com os nomes **Cliente** e **Mota e Silva Advogados**. O tipo anteriormente exibido como Caixa passa a se chamar **Dinheiro**, preservando os saldos existentes. Depois, cadastre as despesas pagas na aba **Despesas de processos** do corretor, informando data, valor, descrição e conta. Cada cadastro gera uma única saída no financeiro. A aba mostra o valor recuperado, o saldo disponível e o histórico dos descontos.
2. Em uma receita recebida em conta, marque **Recebimento de processo**. A divisão abre após salvar. Para uma entrada já cadastrada ou importada por OFX, use **Recebimento de processo** na listagem, sem duplicar o crédito.
3. Informe Cliente ou processo e separe os honorários por valor ou percentual da entrada. O restante é o valor bruto do cliente. O corretor é opcional: mantenha **Sem corretor** quando não houver intermediação.
4. Distribua os honorários por valor ou percentual **dos honorários**. Sem corretor, informe apenas a parte do escritório; todo o restante dos honorários é sua receita. Com corretor, informe também a comissão dele. O saldo é a **Minha receita**, com uma categoria de receita opcional. Quando houver comissão do corretor, o tipo de caso é automaticamente **Recebimento de processo** e o nome da comissão usa o texto de **Cliente ou processo**. Esses dois campos ficam ocultos no formulário; o tipo padrão é criado ou reutilizado e ativado automaticamente. O nome respeita o limite de 160 caracteres do cadastro de comissões. A comissão é criada automaticamente na aba **Comissões** do corretor com o tipo de caso, nome, valor, data e conta do recebimento, sem criar um pagamento ou outra saída bancária.
5. Informe quais despesas daquele corretor deseja recuperar e quanto de cada uma. Elas reduzem o repasse ao cliente, sem aumentar os honorários nem gerar outra entrada bancária.
6. Escolha os adiantamentos a compensar na comissão do corretor. Nenhum adiantamento é aplicado automaticamente às comissões deste novo fluxo, nem ao cadastrar novos adiantamentos depois do recebimento.
7. Confira os valores líquidos e salve. O escritório é fixo: **Mota e Silva Advogados**. Ao salvar, o valor líquido do cliente é reservado como saldo negativo na conta **Cliente**, e a parte do escritório como saldo negativo na conta **Mota e Silva Advogados**. O recebimento integral permanece na conta bancária. O resumo mostra as contas de destino, os valores de cada parte e sua receita. O link **Ver corretor** abre o cadastro que contém a comissão criada. O acompanhamento das comissões continua no módulo de corretores.

Exemplo: entrada de R$ 30.000; honorários de R$ 6.000; cliente bruto de R$ 24.000. Comissão de R$ 1.500 ao corretor e R$ 1.500 ao escritório deixam R$ 3.000 de receita própria. Recuperar R$ 500 de despesas deixa R$ 23.500 para o cliente. Compensar R$ 300 de adiantamentos deixa R$ 1.200 a repassar ao corretor.

## Recebimentos sem corretor

Ao escolher **Sem corretor**, o formulário oculta a comissão, os adiantamentos e as despesas do corretor. A comissão dele é zerada, e o restante dos honorários, após separar a parte do escritório, pertence ao titular. Por exemplo: honorários de R$ 6.000 e escritório de 40% resultam em R$ 2.400 para Mota e Silva Advogados e R$ 3.600 de receita própria. A separação do cliente e os saldos negativos nas contas de repasse continuam funcionando.

O recebimento é salvo com corretor nulo, sem criar corretor fictício ou comissão. Valores antigos de comissão ou descontos enviados por uma tela desatualizada são desconsiderados quando não há corretor. Ao selecionar novamente um corretor, seus campos voltam a ficar disponíveis. Ao editar uma divisão existente, as proteções dos descontos, compensações e pagamentos anteriores continuam válidas.

## Contas fictícias e pagamentos parciais

**Cliente** e **Mota e Silva Advogados** são contas de controle do tipo **Repasse**, normalmente iniciadas em zero. Seus saldos negativos representam obrigações, não saídas bancárias. A criação da divisão gera somente uma reserva negativa em cada conta fictícia, sem contrapartida na conta bancária: o crédito original já registrou todo o dinheiro recebido. Essas reservas não criam receita, despesa ou movimentação de caixa real adicional.

Exemplo: R$ 10.000 entram no Bradesco; R$ 7.000 pertencem ao cliente, R$ 1.000 ao escritório, R$ 1.000 ao corretor e R$ 1.000 ao titular. Depois da divisão, Bradesco permanece com R$ 10.000; Cliente fica em −R$ 7.000 e Mota e Silva Advogados em −R$ 1.000. A comissão do corretor continua no cadastro dele, sem pagamento automático.

Em **Financeiro → Contas → Gerenciar repasses**, escolha o recebimento correspondente e **Registrar repasse**. Informe valor, data efetiva e a conta bancária que pagou. São permitidos pagamentos parciais, inclusive por bancos diferentes. Um pagamento de R$ 2.000 ao cliente deixa Bradesco em R$ 8.000 e Cliente em −R$ 5.000. Ao pagar os R$ 5.000 restantes, Cliente zera e Bradesco fica com R$ 3.000. Ao pagar o escritório, sua conta zera e Bradesco fica com R$ 2.000; depois de pagar o corretor no módulo próprio, restam R$ 1.000 no banco.

Cada pagamento gera uma transferência vinculada: saída na conta bancária e crédito na conta fictícia, reduzindo a obrigação. Não altera a receita própria nem cria nova despesa de categoria. A data deve estar entre o recebimento e hoje, o valor deve ser positivo e não pode superar o saldo daquele recebimento. Reenvios da mesma confirmação não duplicam o pagamento. Contas fictícias não podem ser escolhidas como banco pagador. O histórico mostra pagamentos e saldo restante por recebimento. **Desfazer repasse** restaura as duas contas; desvincular uma saída preexistente preserva o lançamento original e reabre a obrigação.

O resumo financeiro mostra o saldo real das contas bancárias separado dos valores de cliente/escritório a repassar. Os movimentos por conta no fluxo de caixa excluem as contas fictícias. A soma de bancos e contas fictícias representa o saldo líquido dessas obrigações, ainda incluindo eventual comissão do corretor a pagar no módulo dele.

A identificação inicial das contas usa os nomes acima e o tipo Repasse. Nomes repetidos, contas inativas, tipo incorreto ou usar a própria conta de recebimento como destino impedem o salvamento sem registros parciais. Depois da primeira divisão, o vínculo usa o ID da conta, preservando a identidade se seu nome mudar. Não é possível excluir ou trocar o tipo de uma conta enquanto estiver vinculada a divisões.

Editar ou remover uma divisão desfaz suas reservas. Desfazer um desconto de despesa aumenta somente a obrigação com o cliente, sem movimentar o banco. A divisão fica protegida enquanto houver pagamentos ou descontos. A exclusão confirmada do recebimento desfaz também os pagamentos gerados e suas baixas nas contas fictícias, preservando saídas apenas vinculadas, despesas e transferências independentes. Reservas e contrapartidas de pagamentos são protegidas contra edição/exclusão isolada.

A migração `2026_10_20_040000` converte as antigas distribuições já vinculadas às contas de repasse: desfaz apenas as transferências automáticas anteriores, restaura o saldo bancário e cria as obrigações negativas. Pagamentos anteriores recebem sua baixa correspondente na conta fictícia sem outra saída bancária. Mantém o crédito e FITID originais, comissões e movimentações independentes. A conversão pode ser retomada sem duplicar registros. Divisões antigas sem conta de repasse vinculada continuam legadas e não são associadas por suposição.

## Saldos e alterações

- Despesas e adiantamentos podem ser usados parcialmente em vários recebimentos do mesmo corretor. Cada utilização reduz o saldo disponível. Não é possível usar registros de outro corretor, ultrapassar o saldo disponível, o valor do cliente ou a comissão.
- Cálculos e validações usam centavos. No modo percentual, centavos restantes são distribuídos de forma determinística para fechar exatamente os honorários.
- A divisão fica protegida enquanto houver repasses, compensações ou descontos. **Desfazer desconto** libera o saldo da despesa e reabre o valor do cliente. **Desfazer compensação** libera o adiantamento e reabre a comissão. Depois de desfazer os vínculos, é possível editar a divisão.
- Despesas recuperadas e adiantamentos usados neste fluxo ficam protegidos contra edição/exclusão até desfazer os vínculos. Repasses não podem ultrapassar a comissão líquida das compensações escolhidas.
- Pagamentos registrados na versão anterior são preservados no histórico e podem ser desfeitos ou desvinculados para permitir correções. **Desfazer repasse** remove uma saída gerada anteriormente; **Desvincular** preserva uma saída preexistente ou importada. O recebimento não oferece novas ações de pagamento.
- Na listagem, **Excluir** abre uma confirmação única. Excluir o recebimento remove sua divisão, as comissões e os repasses gerados. Libera os descontos e compensações, preservando as despesas e adiantamentos originais. Registros apenas vinculados são preservados. A operação é atômica.

## Relatórios e compatibilidade

O caixa continua mostrando os R$ 30.000 efetivamente recebidos e as saídas reais. O resumo distingue receita própria, valores de terceiros, despesas recuperadas. Na visão por categoria, somente a receita própria entra na categoria escolhida; os demais valores aparecem separadamente como valores para repasse e recuperação de despesas. As despesas de processos também aparecem no extrato do corretor e no total de saídas do período.

Divisões antigas continuam disponíveis com seus valores, comissões e pagamentos. Não são recalculadas nem migradas automaticamente para a nova divisão. Para convertê-las, desfaça os vínculos, abra **Editar divisão** e revise os valores no novo formulário. Comissões anteriores conservam seus dados até a divisão ser editada e salva; nesse momento são aplicados o tipo padrão e o texto atualizado de Cliente ou processo. Entradas sem divisão continuam consideradas receitas próprias no resumo.

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

A migração `2026_10_20_020000_add_repasse_accounts_to_receipt_divisions` acrescenta o tipo Repasse e os vínculos das transferências. Ela não cria as duas contas automaticamente. Para reverter a migração, primeiro remova as distribuições e altere o tipo das contas de repasse.

A migração `2026_10_20_030000_make_process_receipt_broker_optional` permite recebimentos sem corretor e preserva os vínculos existentes. O rollback exige antes vincular um corretor ou remover as divisões que não possuem corretor; ele não atribui um corretor automaticamente nem apaga recebimentos.

Aplique também `2026_10_20_030000_make_process_receipt_broker_optional` e `2026_10_20_040000_convert_repasse_balances_to_obligations` com `php artisan migrate --force`. A segunda migração adapta os saldos já vinculados ao novo controle e preserva pagamentos anteriores.
