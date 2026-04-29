# Cassio Finance

Sistema de gestão financeira pessoal do Dr. Cássio Mota.

Veja [PRD_Sistema_Financeiro_Dr_Cassio.md](PRD_Sistema_Financeiro_Dr_Cassio.md), [design-system.md](design-system.md) e [PLAN.md](PLAN.md).

## Stack

- PHP 8.4 + Laravel 12.x (container)
- Livewire 4 (single-file nativo) + Tailwind 3.4
- MySQL 8.x **no host** (não containerizado)
- Redis 7 (container)
- Nginx alpine (container)

## Setup local

### Pré-requisitos

- Docker Desktop
- MySQL 8 instalado no host com banco `app_cassio` criado

### Primeiro start

```bash
cp .env.example .env
# editar .env: DB_USERNAME, DB_PASSWORD

docker compose build
docker compose up -d

docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

docker compose exec app npm install
docker compose exec app npm run build
```

App disponível em http://localhost:8080

### Comandos úteis

```bash
docker compose exec app php artisan <comando>
docker compose exec app composer <comando>
docker compose logs -f app
docker compose down
```

### Conexão com MySQL no host

Os containers acessam o MySQL do host via `host.docker.internal:3306`.
No MySQL, garanta que o usuário aceita conexões do gateway Docker.

## Estrutura

```
app/Domains/
├── Banking/        # Contas, cartões, lançamentos
├── Brokers/        # Corretores
├── Partnership/    # Sociedade
├── Investments/    # Renda variável + FIIs
├── Writs/          # Requisitórios (RPV/Precatórios)
└── Dashboard/      # Agregadores
```
