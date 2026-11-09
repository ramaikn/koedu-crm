# Menjalankan dan Menguji

Butuh Docker Desktop (Windows: WSL2) atau Docker Engine + Compose (Ubuntu).

## Pertama kali (cara cepat)
Setelah clone, cukup jalankan `sh scripts/setup.sh` (Git Bash atau Linux). Skrip memeriksa Docker dan bun, membuat `.env` dengan rahasia acak, lalu menjalankan semua langkah manual di bawah. Aman diulang. Kunci AI, Google Sheets, dan WhatsApp tetap diisi manual di `.env`.

## Pertama kali (manual)
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
- Test: `bun run build` dulu bila ada halaman frontend baru (manifest Vite harus memuatnya), lalu `docker compose exec app php artisan test` (SQLite in-memory, cepat)
- AI: isi `GEMINI_API_KEYS` di `.env`, `docker compose restart app worker scheduler`, lalu `docker compose exec app php artisan ai:check` (lihat [ai.md](ai.md))
- Impor workflow n8n: `sh scripts/n8n-import.sh` (jalankan dari Git Bash/Linux di folder proyek)
- Setelah mengubah kode PHP yang dipakai antrean: `docker compose restart worker scheduler` (worker tidak memuat ulang kode otomatis)
- Tugas terjadwal Laravel ada di `routes/console.php` (cek: `docker compose exec app php artisan schedule:list`). Saat ini: `enrollments:expire` tiap 00:05
- Typecheck frontend: `bun x tsc --noEmit`
- Log aplikasi: folder `logs/`. Log container: `docker compose logs -f <layanan>`
- Setelah menambah paket Composer: `docker compose run --rm app composer install` (vendor ada di volume Docker)

## Catatan
- Container PHP sengaja tanpa `env_file`, karena Laravel membaca `.env` langsung. Perubahan `.env` cukup diikuti `docker compose restart app worker scheduler`.
- `vendor` disimpan di volume Docker supaya cepat di Windows. Folder `vendor` di host hanya untuk editor.
