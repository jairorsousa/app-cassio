# Valores a receber de terceiros

Controle integrado ao Financeiro para adiantamentos sem juros e despesas pagas por outras pessoas.

## Regras

- A saída bancária mantém seu valor integral no extrato. A parcela atribuída a terceiros é um direito a receber, excluído das despesas próprias.
- Um pagamento pode ser dividido entre várias pessoas. O restante é a despesa própria.
- Uma compra no cartão mantém todas as parcelas nas faturas. As devoluções e os pagamentos das faturas têm controles independentes.
- A despesa própria do cartão é reconhecida conforme a fatura é paga, proporcionalmente à parte própria. A compra não é contada novamente como despesa. Pagamentos parciais usam rateio cumulativo em centavos.
- Devoluções aumentam o banco e reduzem o direito a receber, sem gerar receita própria. É possível criar a entrada ou vincular uma entrada já registrada/importada, com conta, data e valor correspondentes.
- Pagamentos parciais, antecipações e baixas em parcelas específicas são permitidos; nunca é possível baixar mais que o saldo.
- Compensações exigem o mesmo contato na parte do cliente e no valor a receber. Reduzem o saldo a repassar e o direito a receber. Não criam entrada bancária e não alteram os honorários.
- A data da compensação é a do recebimento do processo. Um recebimento não pode anteceder o adiantamento ou ser futuro.
- Vencimentos são opcionais para pagamentos sem previsão ou dependentes de processo. Apenas parcelas com data anterior a hoje são consideradas vencidas.
- Uma perda só é reconhecida explicitamente, com motivo e data. Gera despesa própria sem nova saída bancária; pode ser estornada.
- Estornar uma baixa reabre o saldo. Entradas geradas pela baixa são removidas; entradas previamente existentes são apenas desvinculadas.
- Transações vinculadas são protegidas contra alteração de valor, conta, data, status ou exclusão. A exclusão de grupos de parcelas também verifica vínculos em todo o grupo.
- Remover um controle exige estornar todas as baixas antes. Preserva os lançamentos originais e desfaz sua classificação como valores de terceiros.
- Cadastros, parcelas, vencimentos e baixas têm registro de auditoria. Chaves de confirmação evitam duplicação de baixas e registros repetidos.

## Fluxos de uso

Em **Financeiro → Valores a receber**, crie uma saída bancária, uma compra no cartão ou vincule um lançamento existente. Informe cada pessoa e sua parte. Para amigos e familiares, o tipo de contato **Pessoal** pode ser cadastrado no próprio formulário.

Em **Lançamentos → Dividir com terceiros**, o formulário abre com o pagamento selecionado. Compras parceladas existentes vinculam o grupo completo, sem criar novas compras. Valores antigos não são convertidos automaticamente; a reclassificação é explícita.

Na divisão do **Recebimento de processo**, selecione o contato do cliente e os adiantamentos a descontar. Divisões antigas podem ter o contato vinculado depois. Também é possível fazer a compensação a partir de Valores a receber.

Na tela do contato há um resumo do saldo em aberto. O filtro por pessoa apresenta seu conjunto de registros e os respectivos indicadores.

## Relatórios

A visão geral financeira, o dashboard consolidado e o fluxo de caixa usam a mesma apuração de receitas e despesas próprias. Valores a receber integram o patrimônio consolidado. A movimentação por conta continua mostrando o dinheiro efetivamente movimentado, incluindo adiantamentos e devoluções.

O relatório por categoria distingue a parte própria das outras entradas e saídas. A aquisição de requisitórios recebidos mantém a classificação como recebimento próprio já existente no sistema.

## Implantação

Aplicar as migrations com `php artisan migrate --force` e gerar os assets com `npm run build`. A migration `2026_10_27_000000_create_receivables` cria as três tabelas do controle e o vínculo de contato na parte do cliente. Não reclassifica nem movimenta dados financeiros antigos.

Os testes usam SQLite isolado. A prévia visual também usa um banco temporário com dados fictícios, sem modificar o banco configurado no `.env`.
