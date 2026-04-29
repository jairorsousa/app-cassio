# Documento de Requisitos do Produto (PRD)
## Sistema de Gestão Financeira Pessoal — Dr. Cássio Mota

**Versão:** 1.0
**Data:** 27 de abril de 2026
**Tipo:** PRD Técnico-Funcional

---

## Sumário

1. [Visão Geral](#1-visão-geral)
2. [Objetivos e Critérios de Sucesso](#2-objetivos-e-critérios-de-sucesso)
3. [Persona](#3-persona)
4. [Stack Tecnológica](#4-stack-tecnológica)
5. [Arquitetura do Sistema](#5-arquitetura-do-sistema)
6. [Convenções de Projeto](#6-convenções-de-projeto)
7. [Módulo 1 — Financeiro Pessoal](#7-módulo-1--financeiro-pessoal)
8. [Módulo 2 — Gestão de Corretores](#8-módulo-2--gestão-de-corretores)
9. [Módulo 3 — Sociedade em Escritório](#9-módulo-3--sociedade-em-escritório)
10. [Módulo 4 — Investimentos](#10-módulo-4--investimentos)
11. [Módulo 5 — Requisitórios](#11-módulo-5--requisitórios)
12. [Módulo 6 — Dashboard Consolidado](#12-módulo-6--dashboard-consolidado)
13. [Regras de Negócio Transversais](#13-regras-de-negócio-transversais)
14. [Autenticação e Segurança](#14-autenticação-e-segurança)
15. [Filas e Processamento Assíncrono](#15-filas-e-processamento-assíncrono)
16. [Cache e Performance](#16-cache-e-performance)
17. [Infraestrutura e Deploy](#17-infraestrutura-e-deploy)
18. [Requisitos Não-Funcionais](#18-requisitos-não-funcionais)
19. [Fora do Escopo (V1)](#19-fora-do-escopo-v1)
20. [Roadmap Pós-V1](#20-roadmap-pós-v1)
21. [Glossário](#21-glossário)
22. [Próximos Passos](#22-próximos-passos)

---

## 1. Visão Geral

### 1.1 Contexto

O Dr. Cássio Mota é advogado e possui múltiplas frentes de atuação financeira que hoje são geridas de forma desconectada. Suas atividades incluem:

- Administração das finanças pessoais
- Relacionamento comercial com corretores de causas
- Participação societária em escritório de advocacia
- Operações no mercado financeiro tradicional (renda variável e fundos imobiliários)
- Operações estruturadas de aquisição de requisitórios judiciais (RPVs e Precatórios)

A ausência de uma ferramenta integrada gera retrabalho, dificulta a apuração de rentabilidade real por frente e impede uma visão consolidada do patrimônio.

### 1.2 Objetivo do Produto

Construir um sistema web de uso pessoal e exclusivo do Dr. Cássio que centralize todas as suas operações financeiras em um único ambiente, oferecendo controle operacional, rastreabilidade e visão consolidada de patrimônio.

### 1.3 Usuário

Sistema de **usuário único**. Não haverá perfis adicionais, permissionamento granular ou compartilhamento. Toda a base de dados é privada e dedicada ao Dr. Cássio. A autenticação foi desenhada para esse cenário.

### 1.4 Escopo

Esta versão (v1) compreende seis módulos funcionais e um dashboard consolidado. Funcionalidades de integração externa (Open Finance, B3, e-mail, WhatsApp) e mobile nativo ficam fora do escopo desta entrega.

---

## 2. Objetivos e Critérios de Sucesso

### 2.1 Objetivos

- Centralizar 100% das operações financeiras pessoais e de investimentos em uma única plataforma
- Permitir apuração precisa de rentabilidade por frente de atuação (corretores, sociedade, bolsa, requisitórios)
- Eliminar planilhas paralelas e controles em papel
- Fornecer visão consolidada de patrimônio total atualizada em tempo real
- Reduzir o tempo gasto pelo Dr. Cássio em conciliação e fechamento mensal

### 2.2 Critérios de Sucesso (V1)

- Todas as contas bancárias, cartões e investimentos cadastrados com saldo correto
- Pipeline de requisitórios operacional, com pelo menos um caso percorrendo todas as etapas de ponta a ponta
- Dashboard exibindo patrimônio total consolidado a partir dos seis módulos
- Fechamento mensal de fluxo de caixa concluído em até 30 minutos

---

## 3. Persona

**Dr. Cássio Mota** — Advogado, atua com causas judiciais, opera no mercado financeiro, é sócio de escritório e investe em requisitórios.

- Perfil analítico, com necessidade de precisão e rastreabilidade
- Acessa o sistema via desktop e ocasionalmente via celular
- Espera uma ferramenta enxuta, sem burocracia desnecessária, que reflita rapidamente a realidade das suas operações

---

## 4. Stack Tecnológica

| Camada | Tecnologia | Versão |
|---|---|---|
| Backend | PHP + Laravel | PHP 8.3 / Laravel 11.2 |
| Frontend | Livewire + Volt (single-file components) | Livewire 4 |
| Estilização | Tailwind CSS + Design System | Tailwind 3 |
| Banco de dados | MySQL | 8.x |
| Cache / Sessão | Redis | 7.x |
| Filas | Laravel Queue (Redis driver) | — |
| Servidor web | Nginx | — |
| Containerização | Docker + Docker Compose | — |
| Autenticação | Laravel Breeze (simplificado, single user) | — |

### 4.1 Notas sobre a Stack

- **Livewire 4 + Volt:** componentes em arquivo único reduzem boilerplate e mantêm lógica + view próximas. Adequado para um sistema de uso pessoal com baixa necessidade de SPA complexa.
- **Design System:** mantido em arquivo separado (`design-system.md`) — deve ser a referência única para tokens (cores, espaçamento, tipografia), componentes UI base e padrões de interação.
- **Redis:** centraliza cache de queries pesadas (dashboard, relatórios), sessão de usuário e fila de jobs.
- **Laravel Breeze:** apesar de simplificado, mantém-se hash de senha, proteção CSRF, rate limiting de login e suporte a 2FA.

---

## 5. Arquitetura do Sistema

### 5.1 Visão Macro

```
┌─────────────────────────────────────────────────────────┐
│                      Browser (Dr. Cássio)               │
└────────────────────────┬────────────────────────────────┘
                         │ HTTPS
┌────────────────────────▼────────────────────────────────┐
│                        Nginx                            │
│              (TLS, gzip, static assets)                 │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│            PHP-FPM 8.3  /  Laravel 11.2                 │
│  ┌──────────┐ ┌──────────┐ ┌──────────────────────────┐ │
│  │ Livewire │ │  Routes  │ │   Domain Services        │ │
│  │  + Volt  │ │+ Policies│ │ (por módulo de negócio)  │ │
│  └──────────┘ └──────────┘ └──────────────────────────┘ │
└────────┬───────────────────┬──────────────────┬─────────┘
         │                   │                  │
   ┌─────▼─────┐       ┌─────▼─────┐      ┌─────▼─────┐
   │   MySQL   │       │   Redis   │      │  Workers  │
   │           │       │  (cache,  │      │ (queues)  │
   │           │       │ sessions, │      │           │
   │           │       │  queues)  │      │           │
   └───────────┘       └───────────┘      └───────────┘
```

### 5.2 Organização de Domínios (Backend)

A aplicação será organizada por **módulos de domínio**, cada um com seus próprios Models, Services, Form Requests, Livewire Components e Migrations agrupados logicamente. Estrutura sugerida:

```
app/
├── Domains/
│   ├── Banking/          # Contas, cartões, lançamentos, transferências
│   ├── Brokers/          # Corretores, adiantamentos, comissões
│   ├── Partnership/      # Sociedade no escritório
│   ├── Investments/      # Renda variável, FIIs, proventos
│   ├── Writs/            # Requisitórios (RPV/Precatórios)
│   └── Dashboard/        # Agregadores e read models
├── Models/
├── Services/
└── Support/
resources/
└── views/livewire/       # Componentes Volt por domínio
    ├── banking/
    ├── brokers/
    ├── partnership/
    ├── investments/
    ├── writs/
    └── dashboard/
```

### 5.3 Camadas

- **Livewire/Volt Components**: orquestração de UI e estado de tela
- **Form Requests**: validação de entrada
- **Services**: regras de negócio e orquestração entre múltiplos models
- **Models (Eloquent)**: persistência e relacionamentos
- **Jobs**: processamento assíncrono (recálculos pesados, geração de recorrências)
- **Events / Listeners**: integração entre módulos (ex.: aporte gera lançamento financeiro)

### 5.4 Padrão de Integração entre Módulos

Sempre que um módulo (Corretores, Sociedade, Investimentos, Requisitórios) gerar uma movimentação financeira, ele dispara um **Domain Event** que é capturado por um Listener no módulo `Banking`, o qual cria o lançamento correspondente. Isso mantém os módulos desacoplados.

```
Aporte registrado (Partnership)
        │
        ▼
  AporteRealizado (event)
        │
        ▼
  CriarLancamentoFinanceiro (listener no Banking)
        │
        ▼
  Lancamento (com referência polimórfica ao aporte)
```

---

## 6. Convenções de Projeto

### 6.1 Banco de Dados

- Tabelas em `snake_case`, plural (`bank_accounts`, `broker_advances`)
- Chaves primárias `id` (bigint auto-increment)
- Timestamps padrão Laravel (`created_at`, `updated_at`)
- Soft deletes (`deleted_at`) para entidades com histórico relevante
- Valores monetários armazenados em `decimal(15,2)` (Real, BRL)
- Datas: `date` para eventos contábeis, `datetime` para timestamps

### 6.2 Código

- PSR-12 + Pint
- Migrations versionadas e idempotentes
- Factories e Seeders para ambiente de desenvolvimento
- Testes Feature priorizados sobre testes Unit (cobrir fluxos de negócio)

### 6.3 Livewire/Volt

- Um componente por tela ou bloco funcional
- Estado mínimo no componente — operações pesadas em Services
- Ações destrutivas com confirmação via modal
- Formulários com Form Objects (Livewire 4)

### 6.4 UI

- Toda estilização via classes Tailwind
- Componentes visuais devem seguir o `design-system.md`
- Nenhum CSS customizado fora de `app.css` salvo casos justificados

---

## 7. Módulo 1 — Financeiro Pessoal

### 7.1 Descrição

Núcleo do sistema. Centraliza contas bancárias, cartões de crédito, lançamentos de receitas e despesas, transferências e fluxo de caixa.

### 7.2 Funcionalidades

#### 7.2.1 Contas Bancárias

- Cadastro de múltiplas contas (corrente, poupança, conta investimento)
- Campos: nome do banco, agência, número da conta, tipo, saldo inicial, saldo atual calculado
- Marcar contas como ativas/inativas (sem excluir histórico)
- Visualização de extrato por conta com filtros por período e categoria

**Tabelas envolvidas:** `bank_accounts`, `transactions`

#### 7.2.2 Cartões de Crédito

- Cadastro de cartões com bandeira, banco emissor, limite, dia de fechamento e dia de vencimento
- Lançamento de despesas vinculadas a um cartão, com identificação automática da fatura
- Tela de fatura: total aberto, total fechado, despesas por fatura
- Pagamento de fatura (parcial ou integral) gera débito automático na conta bancária utilizada
- Despesas parceladas: lançamento único gera N parcelas distribuídas entre faturas futuras

**Tabelas envolvidas:** `credit_cards`, `credit_card_invoices`, `transactions`

#### 7.2.3 Categorias de Receita e Despesa

- Categorias customizáveis (criar, editar, desativar)
- Estrutura em dois níveis: categoria → subcategoria
- Indicação clara do tipo (receita/despesa)

**Tabelas envolvidas:** `categories`

#### 7.2.4 Lançamentos

- Receita ou despesa com: data, valor, categoria, conta/cartão, descrição, observações
- Status: pago/recebido ou a pagar/a receber
- Filtros por período, categoria, conta, status
- Edição e exclusão com log

**Tabelas envolvidas:** `transactions`

#### 7.2.5 Lançamentos Recorrentes

- Periodicidade configurável: semanal, mensal, bimestral, trimestral, semestral, anual
- Sistema gera automaticamente os lançamentos futuros conforme a periodicidade
- Pausar, reativar, encerrar uma recorrência

**Tabelas envolvidas:** `recurring_transactions`, `transactions`
**Job:** `GenerateRecurringTransactionsJob` (executado diariamente via Scheduler)

#### 7.2.6 Transferências entre Contas

- Operação especial que registra simultaneamente saída de uma conta e entrada em outra
- Não impacta resultado do mês (não é receita nem despesa)
- Visível em ambos os extratos com identificação clara

**Tabelas envolvidas:** `transactions` (com flag/tipo `transfer` e `related_transaction_id`)

#### 7.2.7 Relatórios de Fluxo de Caixa

- Mensal: receitas, despesas, saldo, comparativo com meses anteriores
- Por categoria: distribuição percentual
- Por conta: movimentação consolidada
- Filtros de período (mês, trimestre, ano, customizado)

**Cache:** resultados de relatórios cacheados em Redis com TTL de 1h, invalidados em qualquer mutação de transação.

### 7.3 Modelo de Dados (Alto Nível)

```
bank_accounts (id, name, bank, agency, number, type, initial_balance, status, ...)
credit_cards (id, name, brand, bank, limit, closing_day, due_day, status, ...)
credit_card_invoices (id, credit_card_id, reference_month, status, total, due_date, ...)
categories (id, parent_id, name, type [income|expense], status, ...)
transactions (
  id, type [income|expense|transfer|invoice_payment],
  date, amount, description, status [pending|settled],
  category_id, bank_account_id, credit_card_id, credit_card_invoice_id,
  related_transaction_id,        -- para transferências
  source_type, source_id,        -- polimórfico: aporte, comissão, cessão, etc.
  installment_group_id, installment_number, installment_total,
  ...
)
recurring_transactions (id, type, amount, category_id, account_id, frequency, start_date, end_date, status, ...)
```

### 7.4 Componentes Volt

- `banking.dashboard` — visão geral de contas e cartões
- `banking.accounts.index` / `.form`
- `banking.cards.index` / `.invoice`
- `banking.transactions.index` / `.form`
- `banking.recurring.index` / `.form`
- `banking.reports.cashflow`

---

## 8. Módulo 2 — Gestão de Corretores

### 8.1 Descrição

Gerencia os corretores que indicam ou intermediam negócios para o Dr. Cássio, controlando dados cadastrais, adiantamentos concedidos e comissões devidas.

### 8.2 Funcionalidades

#### 8.2.1 Cadastro de Corretores

- Dados pessoais: nome, CPF/CNPJ, RG, data de nascimento
- Contato: telefone, e-mail, endereço completo
- Dados bancários: banco, agência, conta, tipo, chave PIX
- Status: ativo/inativo
- Observações livres

**Tabelas:** `brokers`

#### 8.2.2 Adiantamentos

- Registro de adiantamentos com: data, valor, forma de pagamento, conta de origem, observações
- Cada adiantamento gera **automaticamente** despesa em `transactions` via Domain Event
- Tela de saldo do corretor: total adiantado, total compensado, saldo a compensar

**Tabelas:** `broker_advances`
**Event:** `BrokerAdvancePaid` → cria `transaction` vinculada

#### 8.2.3 Comissionamento

- Configuração de regras de comissão por corretor com **percentuais variáveis por tipo de caso**
- Tipos de caso configuráveis pelo usuário (previdenciário, trabalhista, cível, requisitório, etc.)
- Cada corretor pode ter um percentual diferente para cada tipo de caso
- Registro de comissão devida: vincula caso/recebimento → calcula valor automaticamente
- Compensação de adiantamentos: ao registrar comissão, sistema permite abater valores de adiantamentos pendentes
- Pagamento da comissão gera despesa em `transactions`

**Tabelas:**
```
case_types (id, name, status)
broker_commission_rules (id, broker_id, case_type_id, percentage, valid_from, valid_to)
broker_commissions (
  id, broker_id, case_type_id, base_amount, percentage_applied, commission_amount,
  status [pending|paid|partially_paid], reference_date, notes
)
broker_commission_settlements (id, commission_id, advance_id, amount_offset, settled_at)
```

#### 8.2.4 Histórico e Relatórios

- Histórico por corretor: adiantamentos, comissões devidas, comissões pagas, saldo
- Relatório de comissões pagas no período (por corretor e total)
- Relatório de adiantamentos em aberto

### 8.3 Componentes Volt

- `brokers.index` / `.form` / `.show`
- `brokers.advances.index` / `.form`
- `brokers.commissions.index` / `.form` / `.settle`
- `brokers.case-types.index`
- `brokers.reports`

---

## 9. Módulo 3 — Sociedade em Escritório

### 9.1 Descrição

Controla o investimento do Dr. Cássio como sócio em escritório de advocacia, registrando aportes, despesas operacionais nas quais ele participa e calculando a rentabilidade do investimento.

### 9.2 Funcionalidades

#### 9.2.1 Cadastro do Escritório/Sociedade

- Nome do escritório, CNPJ, percentual de participação, data de entrada
- Possibilidade de cadastrar mais de uma sociedade

**Tabelas:** `partnerships`

#### 9.2.2 Aportes

- **Aportes realizados:** data, valor, conta de origem, finalidade, observações
- **Aportes a fazer:** data prevista, valor, status (pendente/realizado)
- Cada aporte realizado gera saída automática em `transactions`
- Visão acumulada: total aportado por sociedade

**Tabelas:** `partnership_contributions`
**Event:** `PartnershipContributionMade` → cria `transaction`

#### 9.2.3 Despesas do Escritório

- Registro de despesas do escritório nas quais o Dr. Cássio participa proporcionalmente
- Campos: data, valor total, descrição, categoria, percentual de participação aplicado
- Sistema calcula automaticamente o valor proporcional devido pelo Dr. Cássio
- Geração de despesa em `transactions` pelo valor proporcional

**Tabelas:** `partnership_expenses`
**Event:** `PartnershipExpenseRecorded` → cria `transaction` proporcional

#### 9.2.4 Rentabilidade do Investimento

- Registro de retiradas/distribuições recebidas: data, valor, origem
- Cada retirada gera receita automática em `transactions`
- Cálculo de rentabilidade: total recebido vs total aportado (R$ e %)
- Cálculo de rentabilidade no período (mensal, trimestral, anual)
- Relatório consolidado: aportes, despesas suportadas, recebimentos, resultado líquido

**Tabelas:** `partnership_distributions`
**Event:** `PartnershipDistributionReceived` → cria `transaction`

### 9.3 Componentes Volt

- `partnership.index` / `.form`
- `partnership.contributions.index` / `.form`
- `partnership.expenses.index` / `.form`
- `partnership.distributions.index` / `.form`
- `partnership.reports.profitability`

---

## 10. Módulo 4 — Investimentos

### 10.1 Descrição

Gerencia operações no mercado de renda variável e fundos imobiliários, com cálculo de posição atual, rentabilidade e controle de proventos recebidos.

### 10.2 Funcionalidades

#### 10.2.1 Cadastro de Ativos

- Por classe: ações, FIIs, ETFs, BDRs, outros
- Campos: ticker, nome, classe, setor (opcional), observações

**Tabelas:** `assets`, `asset_classes`

#### 10.2.2 Operações

- Compra e venda: data, ativo, quantidade, preço unitário, taxas, total
- Cálculo automático de **preço médio ponderado** por ativo
- Vendas calculam automaticamente lucro/prejuízo realizado
- Cada operação gera movimentação na conta bancária/corretora correspondente

**Tabelas:** `asset_operations`
**Job:** `RecalculateAssetPositionJob` (recalcula preço médio do ativo após operação)
**Event:** `AssetOperationRegistered` → cria `transaction`

#### 10.2.3 Posição Atual

- Tela com posição consolidada: ativo, quantidade, preço médio, valor investido
- **Cotação atual editável manualmente** (sem integração com B3 na v1)
- Cálculo de valor de mercado e rentabilidade não realizada
- Visão por classe de ativo (% do portfólio)

**Tabelas:** `asset_positions` (read model atualizado por job), `asset_quotes` (cotações manuais)

#### 10.2.4 Proventos

- Registro de dividendos, JCP e rendimentos de FIIs: data de pagamento, ativo, valor por cota, quantidade na data, valor total
- Cada provento gera receita automática em `transactions`
- Histórico de proventos por ativo e total no período

**Tabelas:** `asset_dividends`
**Event:** `DividendReceived` → cria `transaction`

#### 10.2.5 Rentabilidade

- Por ativo: lucro/prejuízo realizado + não realizado + proventos
- Da carteira: total investido vs valor de mercado vs proventos
- Yield on Cost (YoC) por ativo
- Relatórios por período

### 10.3 Componentes Volt

- `investments.dashboard`
- `investments.assets.index` / `.form`
- `investments.operations.index` / `.form`
- `investments.dividends.index` / `.form`
- `investments.positions`
- `investments.reports.profitability`

---

## 11. Módulo 5 — Requisitórios

### 11.1 Descrição

Módulo dedicado à operação de aquisição de requisitórios judiciais. Implementa um pipeline visual (kanban) com cinco etapas, controlando dados do crédito, partes envolvidas, deságio aplicado, rentabilidade esperada e movimentação financeira.

### 11.2 Tipos de Requisitório

- **RPV** — Requisição de Pequeno Valor
- **Precatório**

### 11.3 Pipeline

| # | Etapa | Descrição |
|---|---|---|
| 1 | **Negociação** | Tratativas iniciais com o cedente. Análise do crédito, definição de deságio, validação documental. |
| 2 | **Cessão Pendente** | Negociação fechada, aguardando assinatura do contrato de cessão e/ou pagamento. |
| 3 | **Pago** | Pagamento ao cedente realizado. Cessão formalizada. |
| 4 | **Peticionar no Processo** | Etapa processual: protocolo da petição de habilitação do cessionário no processo judicial. |
| 5 | **Finalizar** | Recebimento efetivo do requisitório pelo Dr. Cássio. Encerramento da operação. |

### 11.4 Dados de Cada Card

#### 11.4.1 Identificação

- Tipo (RPV / Precatório)
- Número do processo judicial
- Vara / Tribunal de origem
- Ente devedor (União, Estado, Município, autarquia, etc.)
- Natureza do crédito (alimentar, comum, etc.)

#### 11.4.2 Partes

- Cedente (credor original): nome, CPF/CNPJ, contato, dados bancários
- Advogado do cedente (se houver)

#### 11.4.3 Valores e Deságio

- Valor de face do requisitório
- Valor pago ao cedente (após deságio)
- Percentual de deságio (calculado automaticamente)
- Valor estimado de recebimento (líquido)
- Rentabilidade bruta estimada (R$ e %)
- Prazo estimado de recebimento (em meses)

#### 11.4.4 Rentabilidade Realizada

- Valor efetivamente recebido (preenchido na etapa Finalizar)
- Rentabilidade real (R$ e %)
- Tempo decorrido entre pagamento e recebimento
- Rentabilidade ao mês equivalente

#### 11.4.5 Movimentação Financeira

- Pagamento ao cedente: gera **despesa automática** na data registrada na etapa "Pago"
- Recebimento do requisitório: gera **receita automática** na etapa "Finalizar"

### 11.5 Funcionalidades de Gestão do Pipeline

- Visualização kanban com cards distribuídos pelas cinco etapas
- Movimentação por arrastar-e-soltar entre etapas (Livewire + Alpine.js + SortableJS)
- Filtros por tipo, ente devedor, período
- Indicadores no topo do kanban: total em valor de face por etapa, total investido em aberto, total recebido no período
- Relatório de operações encerradas com rentabilidade média realizada
- Histórico de movimentações de cada card (etapas e datas de transição)

### 11.6 Modelo de Dados

```
writs (
  id, type [rpv|precatorio], stage [negotiation|pending|paid|petitioning|finalized],
  process_number, court, debtor_entity, credit_nature,
  assignor_name, assignor_document, assignor_contact, assignor_bank_data,
  assignor_lawyer,
  face_value, paid_amount, discount_percentage,
  estimated_receipt_amount, estimated_months,
  actual_receipt_amount, paid_at, finalized_at,
  notes
)
writ_stage_history (id, writ_id, from_stage, to_stage, transitioned_at, notes)
```

**Eventos de transição:**
- `WritMovedToPaid` → cria despesa em `transactions`
- `WritMovedToFinalized` → cria receita em `transactions` e calcula rentabilidade real

### 11.7 Componentes Volt

- `writs.kanban` — visão pipeline (tela principal)
- `writs.form` — criação/edição
- `writs.show` — detalhamento
- `writs.reports`

---

## 12. Módulo 6 — Dashboard Consolidado

### 12.1 Descrição

Tela inicial do sistema. Apresenta visão consolidada de patrimônio e desempenho integrando os cinco módulos operacionais.

### 12.2 Componentes

#### 12.2.1 Patrimônio Total

- Soma de saldos em contas bancárias
- (+) Valor de mercado da carteira de investimentos
- (+) Saldo investido em sociedade (aportes − retiradas)
- (+) Valor investido em requisitórios em aberto (etapas Pago, Peticionar, Finalizar)
- (−) Saldo de faturas de cartão em aberto
- Comparativo com o mês anterior (variação absoluta e %)

#### 12.2.2 Resumo Financeiro do Mês

- Receitas e despesas do mês corrente (consolidado de todas as fontes)
- Resultado do mês (receitas − despesas)
- Comparativo com média dos últimos 3, 6 e 12 meses

#### 12.2.3 Distribuição do Patrimônio

- Gráfico de pizza: caixa, renda variável, FIIs, sociedade, requisitórios

#### 12.2.4 Indicadores Operacionais

- Faturas de cartão a vencer nos próximos 30 dias
- Lançamentos a pagar e a receber pendentes
- Adiantamentos a corretores em aberto
- Requisitórios em cada etapa do pipeline (contagem e valor)
- Aportes futuros previstos em sociedade

#### 12.2.5 Atalhos Rápidos

- Botões para: lançar receita, lançar despesa, registrar transferência, novo requisitório

### 12.3 Implementação

- **Read model agregado** atualizado por job (`RefreshDashboardSnapshotJob`) disparado:
  - A cada mutação relevante (via event listener)
  - Como fallback, via Scheduler a cada 15 minutos
- Snapshot armazenado em Redis com TTL de 30 minutos
- Gráficos via biblioteca leve (sugestão: Chart.js)

### 12.4 Componentes Volt

- `dashboard.index`

---

## 13. Regras de Negócio Transversais

- Toda movimentação financeira nos módulos 2, 3, 4 e 5 que envolva entrada ou saída de dinheiro deve gerar **automaticamente** um lançamento correspondente no módulo Financeiro Pessoal, vinculando à conta bancária utilizada.
- Lançamentos automáticos gerados por outros módulos **não podem ser editados diretamente** no Financeiro Pessoal — somente na origem.
- Exclusão de lançamento de origem (ex.: aporte) deve refletir na exclusão do lançamento financeiro associado, com **confirmação explícita** do usuário.
- Datas futuras são permitidas em lançamentos previstos (recorrências, aportes a fazer, faturas a vencer).
- Valores monetários sempre em Real (BRL), com duas casas decimais. Tipo `decimal(15,2)` no banco.
- Histórico (logs) de todas as alterações relevantes deve ser preservado. Sugestão: pacote `spatie/laravel-activitylog`.

---

## 14. Autenticação e Segurança

### 14.1 Autenticação

- **Laravel Breeze** (versão Blade/Livewire) simplificado para usuário único
- Tela de login com e-mail + senha
- Hash de senha com bcrypt (padrão Laravel)
- Suporte a **2FA** (TOTP) — recomendado dado o conteúdo financeiro
- Sessão armazenada em Redis com TTL configurável
- Logout automático por inatividade (sugestão: 60 minutos)

### 14.2 Proteções

- CSRF em todas as requisições mutativas (padrão Laravel)
- Rate limiting em rotas sensíveis (login, recuperação de senha)
- Headers de segurança via middleware (`X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security`)
- HTTPS obrigatório em produção
- Senhas e segredos em `.env` (nunca em código)
- Backup criptografado da base de dados

### 14.3 Auditoria

- Log de tentativas de login (sucesso/falha)
- Log de alterações em registros financeiros (quem, quando, antes/depois)
- Log retido por no mínimo 12 meses

---

## 15. Filas e Processamento Assíncrono

### 15.1 Driver

Laravel Queue com driver **Redis**. Worker dedicado em container separado.

### 15.2 Filas Sugeridas

| Fila | Prioridade | Tipo de Job |
|---|---|---|
| `default` | normal | Eventos de domínio (criação de lançamentos) |
| `recalc` | normal | Recálculos de posição/rentabilidade |
| `reports` | baixa | Geração de relatórios pesados |
| `notifications` | normal | (futuro) Notificações |

### 15.3 Jobs Principais

- `GenerateRecurringTransactionsJob` — gera lançamentos a partir de recorrências (Scheduler diário 00:05)
- `RecalculateAssetPositionJob` — recalcula preço médio e posição após operação
- `RefreshDashboardSnapshotJob` — atualiza snapshot do dashboard
- `CloseInvoiceJob` — fecha fatura de cartão no dia configurado (Scheduler diário)

### 15.4 Scheduler

Configurado em `app/Console/Kernel.php`. Executado pelo container worker via `php artisan schedule:work`.

---

## 16. Cache e Performance

### 16.1 Estratégia de Cache (Redis)

| Conteúdo | TTL | Invalidação |
|---|---|---|
| Snapshot do dashboard | 30 min | Eventos de mutação relevantes |
| Relatórios de fluxo de caixa | 1 hora | Mutação em `transactions` |
| Posições de investimentos | 15 min | Operação ou atualização de cotação |
| Listas de categorias / tipos de caso | 1 hora | Edição direta |

### 16.2 Performance

- Eager loading obrigatório para evitar N+1 (uso de `with()`)
- Índices em colunas de busca frequente: `transactions.date`, `transactions.bank_account_id`, `writs.stage`, `broker_commissions.broker_id`
- Paginação em todas as listagens (padrão 25 itens/página)
- Lazy loading de Livewire em componentes pesados do dashboard

---

## 17. Infraestrutura e Deploy

### 17.1 Containers (Docker Compose)

| Container | Imagem base | Função |
|---|---|---|
| `nginx` | nginx:alpine | Reverse proxy, TLS, static |
| `app` | php:8.3-fpm | Aplicação Laravel (PHP-FPM) |
| `worker` | php:8.3-cli | Workers de fila + scheduler |
| `mysql` | mysql:8 | Banco de dados |
| `redis` | redis:7-alpine | Cache, sessão, fila |

### 17.2 Volumes

- `mysql_data` — persistência do banco
- `redis_data` — persistência do Redis (com AOF)
- `app_storage` — arquivos do Laravel `storage/`

### 17.3 Variáveis de Ambiente

`.env.example` versionado. `.env` real não versionado. Variáveis principais:

```
APP_ENV, APP_KEY, APP_URL
DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
REDIS_HOST, REDIS_PASSWORD
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
CACHE_DRIVER=redis
```

### 17.4 Backup

- Dump diário do MySQL para volume externo (cron container ou job)
- Retenção mínima de 30 dias
- Teste mensal de restauração

### 17.5 Ambientes

- **Local:** Docker Compose, dados de seed
- **Produção:** Docker Compose em VPS ou similar; HTTPS via Let's Encrypt; deploy via git pull + `php artisan migrate --force` + `php artisan optimize`

---

## 18. Requisitos Não-Funcionais

### 18.1 Usabilidade

- Sistema responsivo, prioridade desktop
- Operações frequentes (lançamento de receita/despesa) acessíveis em no máximo dois cliques a partir do dashboard
- Confirmação antes de operações irreversíveis

### 18.2 Desempenho

- Tempo de carregamento de telas principais inferior a 2s
- Cálculos de relatórios com volume típico em até 5s
- Dashboard renderizado em até 3s (com cache quente)

### 18.3 Disponibilidade

- Sistema de uso pessoal — não há SLA formal
- Backup diário garantindo RPO de 24h

### 18.4 Manutenibilidade

- Cobertura de testes: foco em testes Feature dos fluxos críticos (lançamento, transição de pipeline, cálculos de rentabilidade)
- Documentação inline em Services com regras de negócio complexas
- Migrations reversíveis sempre que possível

---

## 19. Fora do Escopo (V1)

- Integrações automáticas com bancos via Open Finance
- Integrações com B3, corretoras ou provedores de cotação em tempo real
- Aplicativo mobile nativo (iOS/Android)
- Multiusuário, perfis de acesso, compartilhamento de dados
- Geração de declaração de imposto de renda
- Cálculos tributários automáticos sobre operações de renda variável
- Notificações por e-mail, SMS ou WhatsApp
- Importação de extratos bancários (OFX, CSV)

---

## 20. Roadmap Pós-V1

| Fase | Entrega |
|---|---|
| **2** | Importação de extratos bancários (OFX/CSV) e conciliação |
| **3** | Notificações de vencimentos e alertas operacionais (e-mail, push) |
| **4** | Integração com cotações em tempo real para carteira de investimentos |
| **5** | App mobile companion (PWA ou nativo) para lançamentos rápidos |
| **6** | Módulo tributário e relatórios para Imposto de Renda |

---

## 21. Glossário

- **RPV** — Requisição de Pequeno Valor; modalidade de pagamento de débitos judiciais da Fazenda Pública até determinado limite, com prazo de pagamento mais curto.
- **Precatório** — Requisição de pagamento de débito judicial da Fazenda Pública acima do limite de RPV, com pagamento sujeito a fila e ordem cronológica.
- **Cedente** — Credor original do requisitório que cede o crédito ao Dr. Cássio.
- **Cessionário** — Quem adquire o crédito por cessão (no caso, Dr. Cássio).
- **Deságio** — Diferença entre o valor de face do requisitório e o valor pago ao cedente.
- **Aporte** — Investimento de capital realizado pelo sócio na sociedade.
- **Provento** — Distribuição de resultados pagos por ativos financeiros (dividendos, JCP, rendimentos de FIIs).
- **Yield on Cost (YoC)** — Rendimento (proventos) calculado sobre o preço médio de aquisição do ativo.
- **Volt** — Sintaxe single-file do Livewire 4 para componentes.
- **Read Model** — Estrutura de dados otimizada para leitura/agregação, atualizada por eventos.

---

## 22. Próximos Passos

1. Validação deste PRD pelo Dr. Cássio
2. Criação do `design-system.md` (tokens, componentes UI, padrões de interação)
3. Modelagem de dados detalhada (schema completo com índices e constraints)
4. Wireframes/protótipos das telas principais (dashboard, kanban de requisitórios, lançamento de transação)
5. Setup do ambiente Docker e estrutura inicial do projeto
6. Planejamento de entregas incrementais por módulo, sugerindo a ordem:
   1. Financeiro Pessoal (base para todos os outros)
   2. Dashboard básico
   3. Requisitórios (módulo de maior valor de negócio)
   4. Corretores
   5. Investimentos
   6. Sociedade

---

*Documento elaborado em 27 de abril de 2026.*
