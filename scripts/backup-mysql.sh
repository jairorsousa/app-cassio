#!/usr/bin/env bash
#
# Backup diário do MySQL do Cassio Finance.
# Roda no host (não em container). Política: gzip + retenção 30 dias.
#
# Uso (manual):
#   ./scripts/backup-mysql.sh
#
# Cron (rodar diário às 02:00):
#   0 2 * * *  /caminho/para/projeto/scripts/backup-mysql.sh >> /var/log/cassio-backup.log 2>&1
#

set -euo pipefail

# === Config (sobrescreva via .env.backup ou variáveis de ambiente) =========
BACKUP_DIR="${BACKUP_DIR:-/var/backups/cassio}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-app_cassio}"
DB_USER="${DB_USER:-cassio_backup}"
DB_PASSWORD="${DB_PASSWORD:-}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

# === Carrega .env.backup se existir =======================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "${SCRIPT_DIR}/.env.backup" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "${SCRIPT_DIR}/.env.backup"
    set +a
fi

# === Validação ============================================================
if [[ -z "${DB_PASSWORD}" ]]; then
    echo "[backup] ERRO: defina DB_PASSWORD em scripts/.env.backup ou no ambiente." >&2
    exit 1
fi

mkdir -p "${BACKUP_DIR}"

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
OUTPUT_FILE="${BACKUP_DIR}/cassio_${TIMESTAMP}.sql.gz"
LATEST_LINK="${BACKUP_DIR}/cassio_latest.sql.gz"

echo "[backup] $(date -Iseconds) | iniciando dump → ${OUTPUT_FILE}"

# === Dump =================================================================
mysqldump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USER}" \
    --password="${DB_PASSWORD}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    --set-gtid-purged=OFF \
    "${DB_NAME}" \
    | gzip -9 > "${OUTPUT_FILE}"

# Symlink "latest"
ln -sfn "${OUTPUT_FILE}" "${LATEST_LINK}"

SIZE="$(du -h "${OUTPUT_FILE}" | awk '{print $1}')"
echo "[backup] $(date -Iseconds) | dump concluído (${SIZE})"

# === Retenção =============================================================
DELETED="$(find "${BACKUP_DIR}" -maxdepth 1 -name 'cassio_*.sql.gz' -type f -mtime +${RETENTION_DAYS} -print -delete | wc -l)"
echo "[backup] $(date -Iseconds) | rotação: ${DELETED} arquivo(s) > ${RETENTION_DAYS} dias removido(s)"

# === Verificação básica ===================================================
if ! gzip -t "${OUTPUT_FILE}" 2>/dev/null; then
    echo "[backup] ERRO: arquivo gzip corrompido!" >&2
    exit 2
fi

echo "[backup] $(date -Iseconds) | OK"
