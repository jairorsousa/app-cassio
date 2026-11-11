# Valores a receber de terceiros

Controle integrado ao Financeiro para adiantamentos e despesas pagas por outras pessoas (**Paguei por alguém**), com encargos opcionais.

## Regras

- A saída bancária mantém seu valor integral no extrato. A parcela atribuída a terceiros é um direito a receber, excluído das despesas próprias.
- Um pagamento pode ser dividido entre várias pessoas. O restante é a despesa própria.
- Uma compra no cartão mantém todas as parcelas nas faturas. As devoluções e os pagamentos das faturas têm controles independentes.
- A despesa própria do cartão é reconhecida conforme a fatura é paga, proporcionalmente à parte própria. A compra não é contada novamente como despesa. Pagamentos parciais usam rateio cumulativo em centavos.
- **Encargos opcionais (padrão nenhum / zero nos registros antigos):**
  - Tipo de juros (padrão **Simples**; registros já existentes continuam simples):
    - Simples: `total = P × (1 + i × n)`;
    - Compostos: `total = P × (1 + i)^n`;
    com `i = taxa_am/100` e `n = dias/30` (mesma periodicidade mensal e o mesmo pro rata já usados).
  - Juros combinados: desde a data do pagamento ou data inicial informada, sobre o principal vigente em cada intervalo.
  - Após o vencimento: multa uma vez `principal × (multa_pct/100)` + mora com a mesma fórmula do tipo escolhido para a mora (`n = dias_atraso/30`).
  - Exemplos (simples): R$ 1.000 a 2% a.m. por 90 dias = R$ 60; multa 2% + mora 1% a.m. por 30 dias = R$ 30. Compostos no mesmo prazo: R$ 61,21.
  - Cada taxa (juros combinados e mora) tem o próprio seletor Simples/Compostos, com prévia dos dois totais na tela. O tipo aparece nos detalhes. Dá para trocar o tipo e as taxas depois, recalculando o saldo em aberto (baixas já registradas não são refeitas).
  - Saldo = principal em aberto + juros em aberto + multa em aberto − baixas já alocadas.
  - **Alocação de baixas:** 1) multa, 2) juros (combinados + mora), 3) principal. O principal em aberto é o contratado menos o principal já baixado.
- Devoluções aumentam a conta escolhida (incluindo **Dinheiro** / espécie) e reduzem o direito a receber. O principal recuperado **não** é receita; juros/multa recebidos entram no resultado. É possível criar a entrada ou vincular uma entrada já registrada/importada.
- Compensações em processo e reconhecimento de perda permanecem. Na perda, só o principal vira despesa própria; encargos renunciados não geram resultado.
- Remover um controle exige estornar todas as baixas antes.

## Empréstimos concedidos (módulo distinto)

Em **Financeiro → Empréstimos**: dinheiro emprestado a um contato.

- Ao salvar, a saída na conta de origem é conversão caixa→crédito a recuperar (categoria de sistema **Empréstimo concedido**), **não** despesa de resultado.
- Juros contratados (% a.m., padrão zero; tipo padrão **Simples**, com opção de **Compostos**) só viram receita na categoria **Juros de empréstimos** quando efetivamente recebidos. Principal recebido não é receita. A edição permite trocar o tipo e recalcular, enquanto não houver devoluções.
- Baixas totais/parciais por parcela, em qualquer conta ativa (incl. Dinheiro), com histórico e estorno.
- Exemplo: R$ 5.000 em 5× sem juros, duas parcelas pagas ⇒ saldo R$ 3.000.
- Comprovante opcional PDF/JPG/PNG em disco privado.

## Fluxos de uso

Em **Lançamentos**:
- Na **lista**, o link **Paguei por alguém** classifica uma saída já salva (`transaction=ID`), sem duplicar.
- Nos modais **Nova/Editar despesa** e **Nova/Editar despesa no cartão**, o botão **Paguei por alguém** (rodapé) abre Valores a receber. Em lançamento novo, leva os dados como rascunho e **não** grava a despesa no modal — a saída é criada só ao salvar em Valores a receber. Em edição, usa o lançamento existente.
- Não aparece em **Receita** nem **Transferência**.

Ou crie direto pelo menu **Valores a receber**.

## Relatórios

Caixa/Competência: principal a recuperar fora do resultado; juros/multa recebidos na receita. Dashboard personalizável (Etapa 7) não foi alterado nesta etapa.

## Implantação

Migration `2026_11_05_600000_etapa6_receivables_charges_and_loans`. Encargos antigos ficam zerados; baixas antigas têm `principal_amount = amount`.

Migration `2026_11_11_053000_add_interest_type_to_loans_and_receivables`: colunas `loans.interest_type`, `receivables.interest_type` e `receivables.late_interest_type`, padrão `simple`.
