#!/bin/sh
# Membuat database terpisah untuk n8n supaya data workflow tidak bercampur dengan data CRM.
# Hanya jalan sekali, saat volume Postgres masih kosong.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE ${N8N_DB_NAME:-n8n};
EOSQL
