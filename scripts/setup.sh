#!/bin/sh
# Setup sekali jalan: dari hasil git clone sampai aplikasi hidup di http://localhost:8000.
# Pemakaian (dari folder proyek, Git Bash di Windows atau terminal Linux/macOS): sh scripts/setup.sh
# Aman dijalankan ulang: .env yang sudah ada tidak ditimpa, hanya nilai rahasia yang kosong diisi.
set -e

# Git Bash di Windows mengubah argumen berawalan "/" menjadi path Windows. Matikan, tidak berpengaruh di Linux.
export MSYS_NO_PATHCONV=1

cd "$(dirname "$0")/.."

need() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Perlu '$1' tetapi belum terpasang. $2"
        exit 1
    fi
}

need docker "Pasang Docker Desktop (Windows: aktifkan WSL2) lalu jalankan ulang."
need bun "Pasang bun dari https://bun.sh lalu jalankan ulang."
docker info >/dev/null 2>&1 || { echo "Docker terpasang tetapi belum jalan. Buka Docker Desktop dulu."; exit 1; }

# Teks acak heksadesimal sepanjang $1 karakter.
random_hex() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex "$(($1 / 2))"
    else
        LC_ALL=C tr -dc 'a-f0-9' </dev/urandom | head -c "$1"
    fi
}

# Isi KEY di .env hanya bila nilainya masih kosong.
fill_if_empty() {
    if grep -q "^$1=$" .env; then
        sed "s|^$1=$|$1=$2|" .env >.env.tmp && mv .env.tmp .env
        echo "  .env: $1 diisi otomatis"
    fi
}

if [ ! -f .env ]; then
    cp .env.example .env
    echo "File .env dibuat dari .env.example"
fi

fill_if_empty DB_PASSWORD "$(random_hex 24)"
fill_if_empty WAHA_API_KEY "$(random_hex 32)"
fill_if_empty WAHA_DASHBOARD_PASSWORD "$(random_hex 16)"
fill_if_empty N8N_ENCRYPTION_KEY "$(random_hex 32)"
fill_if_empty N8N_API_TOKEN "$(random_hex 32)"

echo "Membangun image dan memasang dependensi PHP..."
docker compose build app
docker compose run --rm --no-deps app composer install

echo "Menyalakan layanan..."
docker compose up -d --wait

# key:generate hanya dijalankan bila APP_KEY masih kosong, supaya sesi login tidak ikut reset.
if grep -q "^APP_KEY=$" .env; then
    docker compose exec -T app php artisan key:generate
fi

docker compose exec -T app php artisan migrate --seed --force

echo "Membangun aset frontend..."
bun install
bun run build

echo ""
echo "Selesai. Aplikasi: http://localhost:8000"
echo "Akun demo ada di database/seeders (kata sandi bawaan: nilai SEED_USER_PASSWORD di .env)."
echo "Fitur AI, Google Sheets, dan WhatsApp aktif setelah kuncinya diisi di .env (lihat docs/agent/knowledge)."
