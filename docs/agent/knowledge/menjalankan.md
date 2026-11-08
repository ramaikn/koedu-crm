# Menjalankan dan Menguji

Butuh Docker Desktop (Windows: WSL2) atau Docker Engine + Compose (Ubuntu).

## Pertama kali
```bash
cp .env.example .env            # isi DB_PASSWORD, WAHA_API_KEY, WAHA_DASHBOARD_PASSWORD, N8N_ENCRYPTION_KEY, N8N_API_TOKEN
docker compose build app
docker compose run --rm --no-deps app composer install
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
bun install && bun run build     # aset frontend (atau pakai profil dev di bawah)
```

## Alamat (hanya dari mesin ini)
| Layanan | URL |
|---|---|
| Aplikasi | http://localhost:8000 |
| n8n | http://localhost:5678 |
| WAHA dashboard | http://localhost:3000/dashboard |

## Sehari-hari
- Hot reload frontend: `docker compose --profile dev up -d` (Vite di port 5173). Hapus `public/hot` bila kembali ke aset build.
- Reset data demo: `docker compose exec app php artisan migrate:fresh --seed`
- Test: `docker compose exec app php artisan test` (SQLite in-memory, cepat)
- Typecheck frontend: `bun x tsc --noEmit`
- Log aplikasi: folder `logs/`. Log container: `docker compose logs -f <layanan>`
- Setelah menambah paket Composer: `docker compose run --rm app composer install` (vendor ada di volume Docker)

## Catatan
- Container PHP sengaja tanpa `env_file`, karena Laravel membaca `.env` langsung. Perubahan `.env` cukup diikuti `docker compose restart app worker scheduler`.
- `vendor` disimpan di volume Docker supaya cepat di Windows. Folder `vendor` di host hanya untuk editor.
