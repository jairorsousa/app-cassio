# Deploy com Cloudflare Tunnel

Guia para publicar este projeto em `https://app.cassiomota.com` usando Docker no VPS e Cloudflare Tunnel.

## 1. DNS e Tunnel na Cloudflare

No painel da Cloudflare Zero Trust:

1. Crie um tunnel para o domínio `cassiomota.com`.
2. Adicione um Public Hostname:
   - Subdomain: `app`
   - Domain: `cassiomota.com`
   - Service: `http://nginx:80`
3. Copie o token do tunnel para usar no `.env` do VPS.

O serviço `http://nginx:80` funciona quando o conector `cloudflared` roda pelo Docker Compose deste projeto, dentro da mesma rede `cassio_net`.

## 2. `.env` de produção

No VPS, configure pelo menos:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.cassiomota.com
APP_BIND=127.0.0.1
APP_PORT=8085
TRUSTED_PROXIES=REMOTE_ADDR

DB_CONNECTION=mysql
DB_HOST=host.docker.internal
DB_PORT=3306
DB_DATABASE=app_cassio
DB_USERNAME=usuario_mysql
DB_PASSWORD=senha_mysql

SESSION_DRIVER=redis
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

CLOUDFLARED_TOKEN=cole_o_token_do_tunnel_aqui
```

`APP_BIND=127.0.0.1` mantém a porta HTTP do Nginx acessível somente localmente no VPS. O acesso público deve entrar pelo Cloudflare Tunnel.

## 3. Subir a aplicação

```bash
docker compose build
docker compose up -d
```

Depois prepare a aplicação:

```bash
docker compose exec app composer install --optimize-autoloader --no-dev
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app npm ci
docker compose exec app npm run build
docker compose exec app php artisan optimize
docker compose exec app php artisan view:cache
```

Se a aplicação já tiver `APP_KEY` válido no `.env`, não rode `key:generate` de novo em deploys seguintes.

## 4. Verificação rápida

No VPS:

```bash
docker compose ps
docker compose logs --tail 80 nginx
docker compose logs --tail 80 app
docker compose logs --tail 80 cloudflared
curl -I http://127.0.0.1:8085/up
```

O `docker compose ps` precisa listar também o container `cassio_cloudflared`. Se ele não aparecer, o conector do tunnel não subiu e a Cloudflare continuará mostrando "No connection detected yet".

No navegador, acesse:

```text
https://app.cassiomota.com/up
```

Se o `/up` abrir via HTTPS mas login, assets ou redirects apontarem para HTTP, confira `APP_URL`, `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE` e rode:

```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan optimize
```

## 6. Upload de arquivos no Livewire (erro 401 em `/livewire/upload-file`)

A rota de upload do Livewire usa URL assinada. Se o domínio ou o esquema (HTTP/HTTPS) usado no navegador não bater com o `APP_URL`, o upload falha com `401 Unauthorized`.

Checklist:

1. `APP_URL` deve ser exatamente a URL pública, com `https://`, por exemplo `https://app.cassiomota.com`.
2. Acesse a aplicação pelo mesmo domínio configurado em `APP_URL` (não misture `www`, IP ou porta local).
3. Depois de alterar domínio ou `APP_URL`, limpe o cache de config:

```bash
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan optimize
```

4. Confirme `TRUSTED_PROXIES=REMOTE_ADDR` (ou `*`) e `SESSION_SECURE_COOKIE=true` em produção.
5. Reinicie o Nginx após mudanças no proxy:

```bash
docker compose restart nginx
```

## 5. Alternativa: cloudflared instalado no host

Se preferir instalar o `cloudflared` diretamente no Ubuntu, remova ou comente o serviço `cloudflared` no `docker-compose.yml` e suba somente a aplicação:

```bash
docker compose up -d
```

Nesse caso, no Public Hostname da Cloudflare use:

```text
http://127.0.0.1:8085
```

O restante das variáveis de Laravel continua igual.
