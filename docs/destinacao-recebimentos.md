# Destinação de recebimentos

A destinação identifica as partes de um recebimento bancário que pertencem a clientes, corretores, escritórios e ao titular. Ela não cria outra entrada nem uma saída enquanto o repasse não ocorrer. O rateio por categoria continua independente.

## Uso

- Em uma receita nova, marque **Definir destinação após salvar**. É necessário selecionar uma conta e marcar a receita como recebida.
- Em uma receita existente, inclusive importada por OFX, clique em **Destinar recebimento**. Não cadastre outra receita para o mesmo crédito.
- Preencha o nome do cliente e do escritório, escolha o corretor cadastrado e informe os valores. Inclua uma única **Minha parte** (pode ser zero). A soma deve ser exatamente igual ao recebimento.
- Na parte do corretor, crie uma comissão de valor fixo selecionando o tipo de caso ou vincule uma comissão existente do mesmo corretor e valor. Uma comissão não pode ser vinculada a dois recebimentos. Comissões novas seguem a compensação por adiantamentos do módulo de corretores.
- Clique em **Registrar repasse** para pagamentos totais ou parciais. Escolha uma conta e registre uma saída nova, ou vincule uma despesa paga já cadastrada/importada. A vinculação usa o valor integral da saída e não cria outra movimentação.

Cliente e escritório são beneficiários de repasse, não contas de destino de uma transferência. Os repasses ao corretor aparecem também no cadastro dele. Pagamentos ou compensações feitos no módulo de corretores são considerados no saldo da destinação.

## Alterações e desfazimento

É possível editar/remover uma divisão enquanto não houver repasses ou compensações. Ao remover, comissões criadas pela divisão são excluídas; comissões preexistentes são apenas desvinculadas.

**Desfazer repasse** remove a saída criada para aquele pagamento. **Desvincular** preserva uma saída que já existia, inclusive uma importada por OFX. Para pagamentos vinculados ao corretor, esse comportamento vale também ao desfazer pelo módulo de corretores.

Enquanto houver destinação, o valor, tipo, conta e status do recebimento ficam protegidos. Saídas vinculadas também ficam protegidas contra edição/exclusão isolada. Compensações por adiantamentos são gerenciadas no módulo de corretores.

## Relatórios

A listagem mostra a parte própria, o saldo ainda a repassar e os beneficiários. O fluxo de caixa mantém as entradas/saídas efetivas e mostra separadamente receitas após a destinação, valores destinados a terceiros e o saldo de repasses dos recebimentos daquele mês. Entradas sem destinação são consideradas próprias nesse resumo. A parte própria de um recebimento não representa o saldo disponível de toda a conta bancária.

## Implantação

O recurso adiciona as tabelas `receipt_destinations` e `receipt_destination_payments` pela migração `2026_10_09_000000_create_receipt_destinations`. Execute as migrações antes de disponibilizar a atualização:

```sh
docker compose exec app php artisan migrate --force
```

Sem Docker, o comando correspondente é `php artisan migrate --force`. A migração preserva os lançamentos existentes; não define destinações automaticamente.
