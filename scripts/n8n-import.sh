#!/bin/sh
# Mengimpor semua workflow di n8n/workflows ke n8n dan mengaktifkannya.
# Pemakaian (dari folder proyek): sh scripts/n8n-import.sh
# Prasyarat: stack berjalan, dan akun owner n8n sudah dibuat lewat http://localhost:5678.
set -e

# Git Bash di Windows mengubah argumen berawalan "/" menjadi path Windows. Matikan, tidak berpengaruh di Linux.
export MSYS_NO_PATHCONV=1

docker compose exec -T n8n n8n import:workflow --separate --input=/workflows

# Impor selalu menonaktifkan workflow. Aktifkan (publish) tiap workflow berdasarkan "id" di file JSON.
for file in n8n/workflows/*.json; do
    id=$(sed -n 's/^ *"id": *"\([^"]*\)",$/\1/p' "$file" | head -n 1)
    docker compose exec -T n8n n8n publish:workflow --id="$id"
done

# n8n memuat webhook workflow aktif saat start, jadi restart supaya terdaftar.
docker compose restart n8n
echo "Selesai. Cek di http://localhost:5678 (workflow harus berstatus Active)."
