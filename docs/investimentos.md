# Investimentos

A área utiliza o padrão do Financeiro: navegação por abas, filtros acima das listas e cadastros em modais.

## Primeiro uso

1. Em **Ativos**, informe o código. Tickers da B3 (`PETR4`, `HGLG11`) preenchem nome, classe e setor automaticamente. CDB e demais códigos continuam manuais. É possível criar uma classe no mesmo formulário. Instituição, vencimento, liquidez e observações são opcionais.
2. Em **Movimentações**, registre compras/aplicações e vendas/resgates com data, quantidade, preço por unidade e taxas. As datas podem ser retroativas, até o dia atual.
3. Selecione uma conta para gerar a saída ou entrada correspondente no Financeiro. Sem conta, a operação afeta somente a carteira. Edições e exclusões também atualizam o lançamento vinculado.
4. Em **Carteira**, as cotações de tickers da B3 (padrão `PETR4`, `HGLG11`) são buscadas automaticamente após o fechamento em dias úteis, via [brapi.dev](https://brapi.dev). O botão **Atualizar cotações** dispara a mesma busca na hora. Clique no preço para registrar uma cotação manual com data. A cotação mais recente por data determina o valor da posição; sem cotação, utiliza-se o preço médio. CDB, Tesouro e demais códigos que não sejam ticker de bolsa continuam manuais.
5. Em **Proventos**, registre dividendos, JCP e rendimentos de FII recebidos, com quantidade e valor por unidade. A conta selecionada recebe o crédito no Financeiro.

Todos os valores são informados em reais. Para aplicações controladas por valor total, é possível registrar uma unidade pelo valor aplicado; um resgate parcial deve usar a fração correspondente dessa unidade. Não há cálculo automático de indexadores, câmbio, juros contratuais ou impostos.

## Indicadores

- Patrimônio: quantidade das posições abertas multiplicada pelo preço de referência.
- Capital em posição: custo remanescente das posições abertas, incluindo taxas de compra.
- Valorização em aberto: patrimônio menos capital em posição.
- Proventos em 12 meses: recebimentos dos últimos 12 meses até hoje, incluindo o dia final.
- Distribuição por classe: participação de cada classe no valor atual da carteira.
- Fluxo mensal: compras, vendas e proventos registrados. Não representa rentabilidade nem evolução histórica do patrimônio.

## Rentabilidade

O relatório considera um período inclusivo e apresenta compras, vendas, taxas, lucro/prejuízo realizado e proventos por ativo. O resultado combina lucro/prejuízo das vendas com os proventos do período. O custo médio é obtido do histórico completo, incluindo compras anteriores ao filtro. É possível exportar os dados em CSV.

O relatório não calcula taxa anualizada ou rentabilidade ponderada pelos aportes. Posições encerradas continuam participando do resultado realizado.

## Integridade do histórico

Vendas não podem ultrapassar a quantidade disponível na sua data. A mesma regra vale ao editar, excluir ou transferir uma operação para outro ativo. Ativos com movimentações não podem ser excluídos; podem ser desativados, mantendo o histórico acessível.

## Publicação

Executar `php artisan migrate --force` e `pnpm build` no fluxo de implantação. A migração `2026_09_09_020000_add_investment_asset_details` acrescenta instituição, vencimento e liquidez ao cadastro de ativos.

Para cotações automáticas, definir `BRAPI_TOKEN` no ambiente. Sem token, a brapi responde apenas PETR4, MGLU3, VALE3 e ITUB4. O job `RefreshAssetQuotesJob` roda em dias úteis às 18:30 (America/Sao_Paulo). Também é possível executar `php artisan investments:refresh-quotes`.

Validação: `php artisan test tests/Feature/Investments tests/Feature/Banking`.
