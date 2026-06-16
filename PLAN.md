# Plano de Ação — Sistema Financeiro Dr. Cássio

**Versão:** 1.0
**Data:** 2026-04-27
**Base:** [PRD_Sistema_Financeiro_Dr_Cassio.md](PRD_Sistema_Financeiro_Dr_Cassio.md) + [design-system.md](design-system.md)

---

## 0. Premissas e Decisões de Infraestrutura

### 0.1 Ajuste em relação ao PRD

- **Aplicação roda em Docker** (decisão do Dr. Cássio): containers para `app` (PHP-FPM), `worker` (queue + scheduler), `nginx` e `redis`.
- **MySQL fora do Docker:** instalado nativamente em dev e produção. Containers acessam via `host.docker.internal` (Windows/Mac) ou IP do gateway (Linux). Configuração via `.env`.
- **Versões escolhidas pela maturidade/estabilidade (verificadas em 27/04/2026):**
  - **PHP 8.4** — em suporte ativo até 31/12/2026 (security até 12/2028). Recomendação oficial PHP.net para produção.
  - **Laravel 12.x** — released em 02/2025, mais maduro que o 13.x recém-lançado em 03/2026. Bug fixes até 08/2026 / security até 02/2027. Aceita PHP 8.2–8.5.
  - **Tailwind 3.4** (instalado pelo Breeze Livewire stack). Mantém compatibilidade com PRD e `tailwind.config.js` clássico.
  - **Livewire 4** — single-file components nativos (Volt foi absorvido no core; pacote separado descontinuado).

### 0.2 Stack confirmada

| Camada | Tecnologia | Onde roda |
|---|---|---|
| Backend | PHP 8.4 + Laravel 12.x | Container `app` (php-fpm) |
| Worker | PHP 8.4 CLI | Container `worker` (queue + scheduler) |
| Web | Nginx alpine | Container `nginx` |
| Frontend | Livewire 4 (single-file nativo) | — |
| CSS | Tailwind 3.4 + tokens do `design-system.md` | — |
| DB | MySQL 8.x | **Host** (não containerizado) |
| Cache/Fila/Sessão | Redis 7.x | Container `redis` |
| Auth | Laravel Breeze (Livewire) + 2FA TOTP | — |

### 0.3 Topologia Docker

```
┌──────────────────────────────────────────────────┐
│                Host (Windows 11)                 │
│                                                  │
│  ┌────────────┐                                  │
│  │   MySQL    │ ◄─── host.docker.internal:3306   │
│  └────────────┘                                  │
│                                                  │
│  ┌──────────────────────────────────────────┐    │
│  │         Docker Compose Network           │    │
│  │  ┌────────┐  ┌────────┐  ┌────────────┐  │    │
│  │  │ nginx  │─▶│  app   │  │   worker   │  │    │
│  │  │ :8080  │  │ php-fpm│  │ queue/sched│  │    │
│  │  └────────┘  └────────┘  └────────────┘  │    │
│  │                  │             │         │    │
│  │            ┌──────────────────────┐      │    │
│  │            │        redis         │      │    │
│  │            └──────────────────────┘      │    │
│  └──────────────────────────────────────────┘    │
└──────────────────────────────────────────────────┘
```

---

## 1. Fase 0 — Setup do Projeto (1–2 dias)

### 1.1 Bootstrap (Laravel 12 + dependências)

- [ ] `composer create-project "laravel/laravel:^12.0" .` (em diretório vazio ou via subdir + move)
- [ ] Instalar Livewire 4: `composer require livewire/livewire:^4`
- [ ] Instalar Breeze: `composer require laravel/breeze --dev` → `php artisan breeze:install livewire`
- [ ] Garantir Tailwind 3.4 + plugin `@tailwindcss/forms` (instalados pelo Breeze)
- [ ] Pint já vem por padrão no Laravel 12
- [ ] Instalar Larastan: `composer require --dev "larastan/larastan:^3.0"` + `phpstan.neon`

### 1.2 Docker stack

- [ ] `docker-compose.yml` com serviços: `app`, `worker`, `nginx`, `redis`
- [ ] `docker/php/Dockerfile` — PHP 8.4-fpm-alpine + extensions (pdo_mysql, redis, intl, gd, zip, bcmath, opcache) + composer
- [ ] `docker/nginx/default.conf` apontando para `app:9000`
- [ ] `docker/php/php.ini` — `memory_limit=512M`, `upload_max_filesize=20M`
- [ ] Healthchecks em `app` e `redis`
- [ ] Volumes: bind do projeto + `vendor_data`/`node_modules_data` named volumes (Windows)
- [ ] `.dockerignore` excluindo `vendor`, `node_modules`, `.git`, `.env`

### 1.3 Configuração de ambiente

- [ ] Criar `.env.example` com:
  ```
  APP_ENV=local
  APP_KEY=
  APP_URL=http://localhost:8080
  DB_CONNECTION=mysql
  DB_HOST=host.docker.internal   # MySQL no host
  DB_PORT=3306
  DB_DATABASE=app_cassio
  DB_USERNAME=
  DB_PASSWORD=
  REDIS_HOST=redis               # nome do serviço no compose
  REDIS_PORT=6379
  REDIS_PASSWORD=null
  CACHE_STORE=redis
  SESSION_DRIVER=redis
  QUEUE_CONNECTION=redis
  ```
- [ ] Validar conexão MySQL: `docker compose exec app php artisan migrate:status`
- [ ] Validar Redis: `docker compose exec app php artisan tinker` → `Cache::put('k','v',60); Cache::get('k');`

### 1.4 Estrutura de domínios

- [ ] Criar diretórios:
  ```
  app/Domains/{Banking,Brokers,Partnership,Investments,Writs,Dashboard}
  resources/views/livewire/{banking,brokers,partnership,investments,writs,dashboard}
  ```
- [ ] Configurar PSR-4 adicional em `composer.json` para `App\\Domains\\`

### 1.5 Design System

- [ ] Criar `resources/css/app.css` com as variáveis CSS do `design-system.md` (seção 15)
- [ ] Importar fonte **Reddit Sans** via Google Fonts no layout principal
- [ ] Configurar `tailwind.config.js` mapeando os tokens (cores primárias, monocromáticas, sistema, espaçamentos, radius, sombras) em `theme.extend`
- [ ] Criar componentes Blade base do design system:
  - `<x-fx.button>` (variantes: primary, standard, mono, text, icon + sm)
  - `<x-fx.input>` (com slots de ícone esquerdo/direito + estados)
  - `<x-fx.badge>` (up/down/neutral)
  - `<x-fx.alert>` (error/success/info)
  - `<x-fx.card>`
  - `<x-fx.table>` (wrapper)
  - `<x-fx.pill>` / `<x-fx.pills>`
  - `<x-fx.menu-item>`
  - `<x-fx.toggle>`
  - `<x-fx.progress>`

### 1.6 Layout autenticado

- [ ] Layout `layouts.app` com sidebar fixa à esquerda + topbar
- [ ] Sidebar com itens: Dashboard, Financeiro, Corretores, Sociedade, Investimentos, Requisitórios, Configurações
- [ ] Topbar com nome do usuário, toggle de tema (claro/escuro via `data-theme`) e logout
- [ ] Aplicar tipografia Reddit Sans + radius/cores do design system

**Entregável da Fase 0:** Projeto rodando, login funcional, layout autenticado renderizado com componentes do design system.

---

## 2. Fase 1 — Autenticação e Segurança (1 dia)

- [ ] Restringir registro de novos usuários (sistema single-user) — desabilitar rota de registro
- [ ] ~~Implementar 2FA TOTP~~ — **adiado para fase posterior** (decisão Dr. Cássio)
- [ ] Configurar rate limiting em `login` e `password.email` (5 tentativas / 1 min)
- [ ] Middleware de headers de segurança (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Strict-Transport-Security` em prod)
- [ ] Logout automático por inatividade (60 min) — middleware customizado checando `last_activity` na sessão
- [ ] Instalar `spatie/laravel-activitylog` para auditoria
- [ ] Logar tentativas de login (sucesso/falha) com IP

**Entregável:** login com 2FA, sessão expirando, logs de auditoria.

---

## 3. Fase 2 — Módulo 1: Financeiro Pessoal (5–7 dias)

> **Crítico:** este módulo é a fundação de todos os outros. Os domínios 2–5 dependem de `transactions` para registrar movimentações automáticas.

### 3.1 Migrations e Models

- [ ] `categories` (parent_id, name, type, status)
- [ ] `bank_accounts` (name, bank, agency, number, type, initial_balance, status)
- [ ] `credit_cards` (name, brand, bank, limit, closing_day, due_day, status)
- [ ] `credit_card_invoices` (credit_card_id, reference_month, status, total, due_date, closed_at)
- [ ] `transactions` — campos completos:
  - `type` enum (income, expense, transfer, invoice_payment)
  - `date`, `amount` decimal(15,2), `description`, `notes`
  - `status` enum (pending, settled)
  - FKs: `category_id`, `bank_account_id`, `credit_card_id`, `credit_card_invoice_id`
  - `related_transaction_id` (transferência espelhada)
  - `source_type`, `source_id` (polimórfico — origem em outros módulos)
  - `installment_group_id`, `installment_number`, `installment_total`
  - Índices: `date`, `bank_account_id`, `credit_card_id`, `(source_type, source_id)`
  - `softDeletes`
- [ ] `recurring_transactions` (type, amount, category_id, account_id, frequency, start_date, end_date, status)

### 3.2 Services e regras

- [ ] `TransactionService::create()` — única porta de entrada para criar lançamentos (interna ou via listener)
- [ ] `TransferService::execute()` — cria 2 transactions vinculadas via `related_transaction_id`
- [ ] `InvoiceService::closeInvoice()` — fecha fatura no `closing_day`
- [ ] `InvoicePaymentService::pay()` — registra pagamento parcial/total + débito em conta
- [ ] `InstallmentService::split()` — gera N parcelas distribuídas em faturas futuras
- [ ] `RecurringTransactionService::generateForToday()` — usado pelo job diário
- [ ] Regra: lançamentos com `source_type` populado são **read-only no UI** (editar só na origem)

### 3.3 Jobs

- [ ] `GenerateRecurringTransactionsJob` (Scheduler diário 00:05)
- [ ] `CloseInvoiceJob` (Scheduler diário 00:10)

### 3.4 Componentes Volt

- [ ] `banking.dashboard` — saldos consolidados, faturas próximas, lançamentos pendentes
- [ ] `banking.accounts.index` + `banking.accounts.form` (modal)
- [ ] `banking.cards.index` + `banking.cards.form` + `banking.cards.invoice` (detalhe)
- [ ] `banking.transactions.index` (filtros: período, categoria, conta, status) + paginação 25/pág
- [ ] `banking.transactions.form` (com lógica de parcelamento e transferência)
- [ ] `banking.recurring.index` + `banking.recurring.form` (pausar/reativar/encerrar)
- [ ] `banking.categories.index` + `.form` (árvore 2 níveis)
- [ ] `banking.reports.cashflow` — mensal, por categoria, por conta + gráficos Chart.js

### 3.5 Cache

- [ ] Cachear relatórios de fluxo (TTL 1h)
- [ ] Invalidar cache em `TransactionObserver::saved/deleted`

### 3.6 Testes Feature

- [ ] Criação de lançamento simples (receita/despesa)
- [ ] Transferência entre contas (cria 2 registros, não impacta resultado)
- [ ] Compra parcelada gera N parcelas em faturas corretas
- [ ] Fechamento de fatura no dia configurado
- [ ] Pagamento de fatura debita conta + atualiza status
- [ ] Geração diária de recorrências
- [ ] Lançamento com `source_type` é read-only no form

**Entregável:** módulo financeiro 100% funcional. Dr. Cássio consegue cadastrar contas, cartões, lançar receitas/despesas, transferir, pagar faturas e ver fluxo de caixa.

---

## 4. Fase 3 — Dashboard Consolidado (básico) (2 dias)

> Implementar agora com os dados disponíveis (apenas Banking). Será expandido conforme módulos forem entrando.

- [ ] Criar `dashboard_snapshots` (uma linha singleton com JSON do estado consolidado + `updated_at`)
- [ ] `RefreshDashboardSnapshotJob` — calcula:
  - Saldo total em contas
  - Faturas em aberto
  - Lançamentos a pagar/receber
  - Resultado do mês corrente
  - Comparativo mês anterior
- [ ] Listener disparando o job em mutações relevantes (transactions, em fases futuras: aportes, operações, etc.)
- [ ] Scheduler de fallback a cada 15 min
- [ ] Cache em Redis (TTL 30 min) com chave `dashboard:snapshot`
- [ ] Componente `dashboard.index`:
  - Card de patrimônio total (com placeholder para módulos futuros)
  - Resumo do mês
  - Gráfico de pizza de distribuição (parcial)
  - Lista de indicadores operacionais
  - Atalhos rápidos (lançar receita/despesa, transferência)

**Entregável:** dashboard renderizado com dados reais do Banking. Estrutura pronta para receber dados dos próximos módulos.

---

## 5. Fase 4 — Módulo 5: Requisitórios (4–5 dias)

> PRD prioriza este como **maior valor de negócio** depois do Banking.

### 5.1 Migrations

- [ ] `writs` (todos os campos da seção 11.6 do PRD)
- [ ] `writ_stage_history` (writ_id, from_stage, to_stage, transitioned_at, notes, user_id)

### 5.2 Domain Events + Listeners

- [ ] `WritMovedToPaid` → cria `transaction` despesa (via `TransactionService` polimórfico)
- [ ] `WritMovedToFinalized` → cria `transaction` receita + calcula rentabilidade real

### 5.3 Service

- [ ] `WritService::transitionTo($writ, $newStage, $context)` — valida transições permitidas, persiste history, dispara events
- [ ] `WritProfitabilityCalculator` — calcula rentabilidade real, tempo decorrido, % ao mês

### 5.4 Componentes Volt

- [ ] `writs.kanban` — 6 colunas com drag-and-drop (Alpine.js + SortableJS via CDN ou npm)
  - Indicadores no topo: total por etapa, total investido, total recebido período
  - Filtros: tipo, ente devedor, período
- [ ] `writs.form` — criação/edição (com seção de partes, valores, deságio calculado)
- [ ] `writs.show` — detalhamento + histórico de transições
- [ ] `writs.reports` — operações encerradas, rentabilidade média

### 5.5 Testes Feature

- [ ] Card percorre as 6 etapas e gera transactions corretas em "Pago" e "Finalizar"
- [ ] Rentabilidade real é calculada corretamente
- [ ] Histórico de transições é persistido

**Entregável:** pipeline de requisitórios end-to-end. Critério de sucesso do PRD ("pelo menos um caso percorrendo todas as etapas") atingido.

---

## 6. Fase 5 — Módulo 2: Corretores (3–4 dias)

### 6.1 Migrations

- [ ] `case_types` (name, status)
- [ ] `brokers` (dados pessoais, contato, dados bancários, status, notes)
- [ ] `broker_advances` (broker_id, date, amount, payment_method, bank_account_id, notes)
- [ ] `broker_commission_rules` (broker_id, case_type_id, percentage, valid_from, valid_to)
- [ ] `broker_commissions` (broker_id, case_type_id, base_amount, percentage_applied, commission_amount, status, reference_date, notes)
- [ ] `broker_commission_settlements` (commission_id, advance_id, amount_offset, settled_at)

### 6.2 Events

- [ ] `BrokerAdvancePaid` → cria despesa em `transactions`
- [ ] `BrokerCommissionPaid` → cria despesa em `transactions`

### 6.3 Service

- [ ] `BrokerCommissionService::register()` — calcula comissão usando regra vigente
- [ ] `BrokerCommissionService::settleWithAdvance()` — abate de adiantamentos pendentes
- [ ] `BrokerBalanceCalculator` — saldo de adiantamentos por corretor

### 6.4 Componentes Volt

- [ ] `brokers.index` + `.form` + `.show` (com saldo + histórico)
- [ ] `brokers.advances.index` + `.form`
- [ ] `brokers.commissions.index` + `.form` + `.settle` (modal de compensação)
- [ ] `brokers.case-types.index` + `.form`
- [ ] `brokers.reports`

**Entregável:** gestão de corretores completa com comissão variável por tipo de caso e compensação de adiantamentos.

---

## 7. Fase 6 — Módulo 4: Investimentos (4 dias)

### 7.1 Migrations

- [ ] `asset_classes` (name) — seed com Ações, FIIs, ETFs, BDRs, Outros
- [ ] `assets` (ticker, name, asset_class_id, sector, notes)
- [ ] `asset_operations` (asset_id, date, type [buy|sell], quantity, unit_price, fees, total, bank_account_id)
- [ ] `asset_positions` (asset_id, quantity, average_price, total_invested) — read model
- [ ] `asset_quotes` (asset_id, date, price) — cotação manual
- [ ] `asset_dividends` (asset_id, payment_date, type [dividend|jcp|fii], unit_amount, quantity, total, bank_account_id)

### 7.2 Jobs/Events

- [ ] `RecalculateAssetPositionJob` — recalcula preço médio após operação
- [ ] `AssetOperationRegistered` → cria transaction (saída em compra, entrada em venda)
- [ ] `DividendReceived` → cria transaction receita

### 7.3 Service

- [ ] `AverageCostCalculator` — preço médio ponderado
- [ ] `RealizedPnLCalculator` — lucro/prejuízo realizado em vendas
- [ ] `PortfolioProfitabilityService` — YoC, retorno total, % por classe

### 7.4 Componentes Volt

- [ ] `investments.dashboard`
- [ ] `investments.assets.index` + `.form`
- [ ] `investments.operations.index` + `.form`
- [ ] `investments.dividends.index` + `.form`
- [ ] `investments.positions` (com edição inline de cotação)
- [ ] `investments.reports.profitability`

**Entregável:** carteira de RV/FIIs com posição, proventos e rentabilidade.

---

## 8. Fase 7 — Módulo 3: Sociedade (2–3 dias)

### 8.1 Migrations

- [ ] `partnerships` (name, cnpj, participation_percentage, joined_at, status)
- [ ] `partnership_contributions` (partnership_id, date, amount, status [pending|done], bank_account_id, purpose, notes)
- [ ] `partnership_expenses` (partnership_id, date, total_amount, applied_percentage, proportional_amount, description, category_id)
- [ ] `partnership_distributions` (partnership_id, date, amount, bank_account_id, source, notes)

### 8.2 Events

- [ ] `PartnershipContributionMade` → transaction despesa
- [ ] `PartnershipExpenseRecorded` → transaction despesa proporcional
- [ ] `PartnershipDistributionReceived` → transaction receita

### 8.3 Service

- [ ] `PartnershipProfitabilityService` — total aportado, total recebido, ROI absoluto e %, por período

### 8.4 Componentes Volt

- [ ] `partnership.index` + `.form`
- [ ] `partnership.contributions.index` + `.form`
- [ ] `partnership.expenses.index` + `.form`
- [ ] `partnership.distributions.index` + `.form`
- [ ] `partnership.reports.profitability`

**Entregável:** controle societário completo.

---

## 9. Fase 8 — Dashboard Consolidado (completo) (1–2 dias)

Expandir o dashboard da Fase 3 incorporando todos os módulos:

- [ ] Patrimônio total = saldos + valor de mercado da carteira + saldo investido em sociedade + valor em requisitórios em aberto − faturas em aberto
- [ ] Distribuição do patrimônio (pizza): caixa, RV, FIIs, sociedade, requisitórios
- [ ] Indicadores: faturas a vencer (30d), pendências de lançamento, adiantamentos a corretores em aberto, requisitórios por etapa, aportes futuros
- [ ] Comparativos: mês atual vs média 3/6/12 meses
- [ ] Listeners atualizando o snapshot a partir de **todos** os módulos

**Entregável:** dashboard cumprindo o critério de sucesso V1 ("patrimônio total consolidado a partir dos seis módulos").

---

## 10. Fase 9 — Polimento, Performance e Hardening (3 dias)

### 10.1 Performance

- [ ] Auditoria N+1 com Telescope/Debugbar — corrigir com `with()` em todas as listagens
- [ ] Adicionar índices identificados em produção
- [ ] Lazy loading de componentes Livewire pesados (`#[Lazy]`)

### 10.2 UX

- [ ] Confirmação modal para ações destrutivas
- [ ] Empty states em todas as listagens
- [ ] Loading states (skeletons) em telas que dependem de cache
- [ ] Toast de sucesso/erro padronizado
- [ ] Atalhos de teclado para criar lançamento (sugestão: `n + r` receita, `n + d` despesa)

### 10.3 Tema escuro

- [ ] Validar todos os componentes em `data-theme="dark"`
- [ ] Persistir preferência em cookie/localStorage

### 10.4 Backup

- [ ] Script de dump diário do MySQL para diretório externo (cron do SO, não Docker)
- [ ] Política de retenção 30 dias
- [ ] Documentar procedimento de restore

### 10.5 Auditoria final

- [ ] Conferir que todas as ações financeiras estão registradas no activitylog
- [ ] Revisar logs por 1 semana e ajustar verbosidade

---

## 11. Fase 10 — Deploy em Produção (1–2 dias)

### 11.1 Servidor

- [ ] Provisionar VPS (Ubuntu LTS recomendado)
- [ ] Instalar PHP 8.3-FPM, Nginx, MySQL 8 (ou apontar para o existente), Redis 7 (idem)
- [ ] Certificado TLS via Let's Encrypt (`certbot`)
- [ ] Configurar Nginx com headers de segurança + HTTPS forçado

### 11.2 Aplicação

- [ ] Deploy via `git pull` + `composer install --no-dev --optimize-autoloader`
- [ ] `npm ci && npm run build`
- [ ] `php artisan migrate --force`
- [ ] `php artisan optimize`
- [ ] Configurar systemd para `queue:work` (worker)
- [ ] Configurar systemd ou cron para `schedule:run` minuto-a-minuto

### 11.3 Smoke test em produção

- [ ] Criar conta bancária + lançamento + transferência
- [ ] Criar requisitório e percorrer pipeline
- [ ] Validar dashboard
- [ ] Validar 2FA
- [ ] Validar backup

---

## 12. Cronograma Resumido

| Fase | Entrega | Estimativa |
|---|---|---|
| 0 | Setup + Design System | 1–2 dias |
| 1 | Auth + Segurança | 1 dia |
| 2 | Módulo Financeiro Pessoal | 5–7 dias |
| 3 | Dashboard básico | 2 dias |
| 4 | Módulo Requisitórios | 4–5 dias |
| 5 | Módulo Corretores | 3–4 dias |
| 6 | Módulo Investimentos | 4 dias |
| 7 | Módulo Sociedade | 2–3 dias |
| 8 | Dashboard completo | 1–2 dias |
| 9 | Polimento + Performance | 3 dias |
| 10 | Deploy em produção | 1–2 dias |
| **Total** | **V1 completa** | **27–35 dias úteis** |

---

## 13. Critérios de Aceitação V1 (do PRD)

- [x/✅] Todas as contas bancárias, cartões e investimentos cadastrados com saldo correto
- [x/✅] Pipeline de requisitórios operacional com pelo menos um caso end-to-end
- [x/✅] Dashboard exibindo patrimônio consolidado dos seis módulos
- [x/✅] Fechamento mensal de fluxo de caixa concluído em até 30 minutos

---

## 14. Riscos e Mitigações

| Risco | Mitigação |
|---|---|
| Acoplamento entre módulos via events vira difícil de debugar | Padronizar nomenclatura, logar todos os eventos no activitylog, escrever testes Feature cobrindo o fluxo completo |
| Drag-and-drop do kanban inconsistente | Usar SortableJS maduro + persistir no `wire:model` com debounce; testar em desktop e mobile |
| Cálculo de preço médio com edição/exclusão retroativa de operações | Sempre recalcular do zero a posição ao mutar uma operação (não fazer delta) |
| Lançamentos automáticos órfãos quando origem é apagada | Forçar exclusão em cascata via observer + confirmação explícita no UI |
| MySQL/Redis no host caindo sem aviso | Healthcheck simples no dashboard mostrando status das dependências |

---

## 15. Decisões em aberto (a confirmar com o Dr. Cássio)

1. Redis está instalado nativamente também? Senão, começar com `database`/`file` drivers?
2. Frontend de gráficos: Chart.js (sugerido) ou outro (ApexCharts, ECharts)?
3. Cor do tema: usar **âmbar** do design system como primário em produção, ou apenas em CTAs?
4. Backup: dump local com retenção 30d basta, ou replicar para storage externo (S3, Backblaze)?
5. 2FA obrigatório ou opcional na primeira versão?
