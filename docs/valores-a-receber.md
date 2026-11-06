# Valores a receber de terceiros

Controle integrado ao Financeiro para adiantamentos e despesas pagas por outras pessoas (**Paguei por alguém**), com encargos opcionais.

## Regras

- A saída bancária mantém seu valor integral no extrato. A parcela atribuída a terceiros é um direito a receber, excluído das despesas próprias.
- Um pagamento pode ser dividido entre várias pessoas. O restante é a despesa própria.
- Uma compra no cartão mantém todas as parcelas nas faturas. As devoluções e os pagamentos das faturas têm controles independentes.
- A despesa própria do cartão é reconhecida conforme a fatura é paga, proporcionalmente à parte própria. A compra não é contada novamente como despesa. Pagamentos parciais usam rateio cumulativo em centavos.
- **Encargos opcionais (padrão nenhum / zero nos registros antigos):**
  - Juros combinados simples: `principal × (taxa_am/100) × (dias/30)`, desde a data do pagamento ou data inicial informada.
  - Após o vencimento: multa uma vez `principal × (multa_pct/100)` + mora `principal × (taxa_mora_am/100) × (dias_atraso/30)`.
  - Exemplos: R$ 1.000 a 2% a.m. por 90 dias = R$ 60; multa 2% + mora 1% a.m. por 30 dias = R$ 30.
  - Saldo = principal em aberto + juros em aberto + multa em aberto − baixas já alocadas.
  - **Alocação de baixas:** 1) multa, 2) juros (combinados + mora), 3) principal. O principal em aberto é o contratado menos o principal já baixado.
- Devoluções aumentam a conta escolhida (incluindo **Dinheiro** / espécie) e reduzem o direito a receber. O principal recuperado **não** é receita; juros/multa recebidos entram no resultado. É possível criar a entrada ou vincular uma entrada já registrada/importada.
- Compensações em processo e reconhecimento de perda permanecem. Na perda, só o principal vira despesa própria; encargos renunciados não geram resultado.
- Remover um controle exige estornar todas as baixas antes.

## Empréstimos concedidos (módulo distinto)

Em **Financeiro → Empréstimos**: dinheiro emprestado a um contato.

- Ao salvar, a saída na conta de origem é conversão caixa→crédito a recuperar (categoria de sistema **Empréstimo concedido**), **não** despesa de resultado.
- Juros contratados (simples % a.m., padrão zero) só viram receita na categoria **Juros de empréstimos** quando efetivamente recebidos. Principal recebido não é receita.
- Baixas totais/parciais por parcela, em qualquer conta ativa (incl. Dinheiro), com histórico e estorno.
- Exemplo: R$ 5.000 em 5× sem juros, duas parcelas pagas ⇒ saldo R$ 3.000.
- Comprovante opcional PDF/JPG/PNG em disco privado.

## Fluxos de uso

Em **Lançamentos**, use **Paguei por alguém** para classificar uma saída existente sem duplicar. Ou crie pelo menu **Valores a receber**.

## Relatórios

Caixa/Competência: principal a recuperar fora do resultado; juros/multa recebidos na receita. Dashboard personalizável (Etapa 7) não foi alterado nesta etapa.

## Implantação

Migration `2026_11_05_600000_etapa6_receivables_charges_and_loans`. Encargos antigos ficam zerados; baixas antigas têm `principal_amount = amount`.
