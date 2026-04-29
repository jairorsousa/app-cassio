# Backup & Restore — Cassio Finance

MySQL fica fora do Docker (no host), então o backup também roda no host. O script
usa `mysqldump --single-transaction` (sem lock para tabelas InnoDB) + `gzip -9` e
mantém uma janela de retenção de 30 dias por padrão.

## 1. Setup inicial (uma vez)

### 1.1 Criar usuário dedicado de backup no MySQL

```sql
CREATE USER 'cassio_backup'@'localhost' IDENTIFIED BY 'troque-essa-senha';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER, PROCESS ON app_cassio.* TO 'cassio_backup'@'localhost';
GRANT RELOAD ON *.* TO 'cassio_backup'@'localhost';   -- opcional, melhora consistência
FLUSH PRIVILEGES;
```

### 1.2 Configurar credenciais

```bash
cp scripts/.env.backup.example scripts/.env.backup
# editar e definir DB_PASSWORD
chmod 600 scripts/.env.backup
chmod +x scripts/backup-mysql.sh scripts/restore-mysql.sh
```

### 1.3 Criar diretório de destino

```bash
sudo mkdir -p /var/backups/cassio
sudo chown "$USER":"$USER" /var/backups/cassio
```

## 2. Agendamento (cron do SO — não Docker)

Edite o crontab (`crontab -e`) e adicione:

```
0 2 * * *  /caminho/absoluto/app-cassio/scripts/backup-mysql.sh >> /var/log/cassio-backup.log 2>&1
```

Roda às 02:00 todo dia. Cada execução: dump comprimido em
`/var/backups/cassio/cassio_YYYY-MM-DD_HHMMSS.sql.gz` + symlink `cassio_latest.sql.gz` +
remoção automática de arquivos com mais de `RETENTION_DAYS` (padrão 30).

## 3. Restore

```bash
# Restaura o dump mais recente (com confirmação interativa)
./scripts/restore-mysql.sh

# Ou um dump específico
./scripts/restore-mysql.sh /var/backups/cassio/cassio_2026-04-29_020000.sql.gz
```

O script pede para o operador digitar o nome do banco como confirmação antes de
sobrescrever.

## 4. Validação periódica recomendada

Mensalmente, fazer um restore de teste em um banco temporário:

```bash
mysql -u root -p -e "CREATE DATABASE app_cassio_dr_test;"
DB_NAME=app_cassio_dr_test ./scripts/restore-mysql.sh
mysql -u root -p app_cassio_dr_test -e "SELECT COUNT(*) FROM transactions;"
mysql -u root -p -e "DROP DATABASE app_cassio_dr_test;"
```

## 5. Off-site (recomendado para produção)

O script grava local. Para off-site (S3, Backblaze, rsync remoto), adicionar uma
linha após o dump:

```bash
# rsync para servidor secundário
rsync -az "${OUTPUT_FILE}" cassio@bkp.host:/cassio-backups/
# ou aws cli
aws s3 cp "${OUTPUT_FILE}" s3://cassio-bkp/$(date +%Y/%m)/
```

## 6. Troubleshooting

| Sintoma | Causa provável | Correção |
|---|---|---|
| `Access denied for user` | Senha errada ou usuário sem `SELECT` | Reconferir GRANTs em 1.1 |
| `gzip corrompido` | Disco cheio durante o dump | Liberar espaço; o script aborta com exit code 2 |
| Dump > 1h | Tabela muito grande sem índice | Verificar com `mysqldump --verbose` qual passo trava |
| `mysqldump: command not found` | mysql-client não instalado | `apt install mysql-client` |
