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

## Deploy em Produção (Ubuntu Server com Docker)

Este guia pressupõe que você já tem o servidor Ubuntu preparado com Docker, Docker Compose e o banco de dados MySQL instalado diretamente no host (não containerizado).

### 1. Preparação Inicial no Servidor

Conecte-se via SSH ao seu servidor Ubuntu e clone o repositório na pasta de sua preferência (ex: `/var/www/app-cassio`).

```bash
# Conecte-se ao servidor
ssh usuario@ip_do_seu_servidor

# Acesse o diretório de projetos e clone o repo
cd /var/www
git clone https://github.com/jairorsousa/app-cassio.git
cd app-cassio
```

### 2. Configuração do Ambiente (.env)

Crie o arquivo de configuração a partir do exemplo:

```bash
cp .env.example .env
nano .env
```

Ajuste as seguintes variáveis essenciais para produção:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seudominio.com.br # ou o IP do servidor
APP_PORT=8085 # Mude para uma porta livre no seu servidor para evitar conflito com outras apps

# Configuração do Banco de Dados no Host
# Graças ao `host.docker.internal:host-gateway` no docker-compose.yml,
# a aplicação no container vai enxergar o MySQL do host por este host:
DB_CONNECTION=mysql
DB_HOST=host.docker.internal
DB_PORT=3306
DB_DATABASE=app_cassio
DB_USERNAME=usuario_mysql
DB_PASSWORD=senha_mysql

# Configuração do Redis (como ele roda via Docker, mantemos o nome do container)
# Nota: O Redis roda isolado na rede do Docker (cassio_net), portanto a porta 6379 
# não entrará em conflito com outras aplicações ou instâncias do Redis no seu host.
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
```

> **Atenção (MySQL no Host):** Certifique-se de que o usuário do MySQL no servidor Ubuntu tenha permissão para se conectar através do IP da rede do Docker (ou use `%` se for seguro no seu ambiente de firewall). Exemplo de comando no MySQL do host:
> `CREATE USER 'usuario_mysql'@'%' IDENTIFIED BY 'senha_mysql';`
> `GRANT ALL PRIVILEGES ON app_cassio.* TO 'usuario_mysql'@'%';`
> `FLUSH PRIVILEGES;`
> E no `/etc/mysql/mysql.conf.d/mysqld.cnf`, a diretiva `bind-address` deve permitir conexões do Docker (ex: `0.0.0.0` ou o IP da interface `docker0`).

### 3. Build e Start dos Containers

Suba os containers da aplicação, web server (nginx) e redis, além dos workers:

```bash
docker compose build
docker compose up -d
```

### 4. Instalação e Otimização da Aplicação

Com os containers rodando, execute as instalações e preparações internas do Laravel focadas em produção:

> **Problemas de Permissão (Erro do Git / Vendor):** Se ao rodar o comando do composer abaixo você receber erros como `fatal: detected dubious ownership in repository` ou `vendor does not exist and could not be created`, significa que os arquivos no host (Ubuntu) não pertencem ao usuário interno do Docker (UID 1000).
> Para resolver isso, rode este comando no seu Ubuntu dentro da pasta do projeto e depois tente o composer novamente:
> `sudo chown -R 1000:1000 .`

```bash
# 1. Instalar as dependências do PHP (sem os pacotes de dev) e otimizar autoloader
docker compose exec app composer install --optimize-autoloader --no-dev

# 2. Gerar a chave da aplicação (apenas na primeira vez)
docker compose exec app php artisan key:generate

# 3. Rodar as migrações do banco de dados
docker compose exec app php artisan migrate --force

# 4. Otimizar e criar cache das configurações, rotas e views
docker compose exec app php artisan optimize
docker compose exec app php artisan view:cache

# 5. Instalar pacotes NPM e compilar assets para produção (Vite)
docker compose exec app npm install
docker compose exec app npm run build
```

### 5. Permissões de Pasta (Opcional, se houver erro)

Geralmente, o volume no docker já reflete as permissões locais, mas caso precise ajustar as permissões de storage para o Nginx/PHP do container:

```bash
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 6. Pronto!

A aplicação agora deve estar rodando na porta configurada na variável `APP_PORT` (por exemplo, `8085`).
Você pode acessá-la por `http://ip_do_servidor:8085`.

Para publicar com domínio real e SSL via Cloudflare Tunnel, siga o guia específico em [docs/deploy-cloudflare-tunnel.md](docs/deploy-cloudflare-tunnel.md). Para o domínio de produção deste projeto, use `APP_URL=https://app.cassiomota.com` e mantenha a porta HTTP do container bindada em `127.0.0.1`.

> **Isolamento no Docker:** Não se preocupe com conflitos de nome. Os containers foram configurados no `docker-compose.yml` com nomes específicos (como `cassio_app`, `cassio_nginx`, `cassio_redis`) e o Redis só expõe a porta dentro da rede interna do docker (`cassio_net`), garantindo que não colida com outras instâncias de Redis do servidor.

---

### Troubleshooting (Resolução de Problemas)

Se você receber um **Erro 500 (Server Error)** após o deploy, aqui estão os passos para descobrir o que está acontecendo:

**1. Verifique os logs do Laravel**
O Laravel registra detalhes precisos dos erros (como problemas de conexão com banco, views não compiladas, etc) no arquivo de log. Rode:
```bash
tail -n 50 storage/logs/laravel.log
# ou no container:
    docker compose exec app tail -n 50 storage/logs/laravel.log
```

**2. Verifique os logs do container (PHP/Nginx)**
Pode ser que o erro não tenha chegado ao Laravel (ex: permissões, falha no Nginx ou no PHP-FPM).
```bash
docker compose logs --tail 50 app
docker compose logs --tail 50 nginx
```

**3. Temporariamente ative o modo Debug**
Se ainda estiver difícil de entender pelo log, você pode exibir o erro direto na tela. 
Vá no arquivo `.env`, altere `APP_DEBUG=false` para `APP_DEBUG=true`.
Limpe o cache executando `docker compose exec app php artisan config:clear` e acesse a página de novo.
**Atenção:** Lembre-se de voltar para `APP_DEBUG=false` assim que resolver o problema, pois o modo debug expõe dados sensíveis do servidor.

**4. Erros Comuns de Deploy**
- Falta de permissões na pasta `storage/`: `sudo chmod -R 777 storage bootstrap/cache`
- Conexão com o banco falhou: verifique se `DB_HOST`, `DB_USER` e `DB_PASSWORD` estão corretos para o MySQL do seu servidor.
- Chave da aplicação (`APP_KEY`) não foi gerada: execute `docker compose exec app php artisan key:generate`.
