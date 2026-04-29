#!/usr/bin/env bash
#
# Restaura backup do Cassio Finance.
#
# Uso:
#   ./scripts/restore-mysql.sh /var/backups/cassio/cassio_2026-04-29_020000.sql.gz
#
# Sem argumento: usa o symlink cassio_latest.sql.gz.

set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-app_cassio}"
DB_USER="${DB_USER:-cassio_backup}"
DB_PASSWORD="${DB_PASSWORD:-}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/cassio}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "${SCRIPT_DIR}/.env.backup" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "${SCRIPT_DIR}/.env.backup"
    set +a
fi

INPUT="${1:-${BACKUP_DIR}/cassio_latest.sql.gz}"

if [[ ! -f "${INPUT}" ]]; then
    echo "[restore] ERRO: arquivo não encontrado: ${INPUT}" >&2
    exit 1
fi

if [[ -z "${DB_PASSWORD}" ]]; then
    echo "[restore] ERRO: defina DB_PASSWORD em scripts/.env.backup." >&2
    exit 1
fi

echo "[restore] AVISO: isto SOBRESCREVERÁ o banco '${DB_NAME}' em ${DB_HOST}."
read -r -p "[restore] Confirme digitando o nome do banco: " CONFIRM
if [[ "${CONFIRM}" != "${DB_NAME}" ]]; then
    echo "[restore] cancelado."
    exit 0
fi

echo "[restore] $(date -Iseconds) | restaurando ${INPUT} → ${DB_NAME}"
gunzip -c "${INPUT}" | mysql \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USER}" \
    --password="${DB_PASSWORD}" \
    --default-character-set=utf8mb4 \
    "${DB_NAME}"
echo "[restore] $(date -Iseconds) | OK"
