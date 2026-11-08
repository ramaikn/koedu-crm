# Setup Docker dan kerangka Laravel

- Waktu: 2026-11-08 19:25 WITA (UTC+8)
- Jenis: setup
- Ringkasan: kerangka Laravel 12 + React (Inertia) sudah dibuat, dan stack Docker Compose lengkap berjalan di lokal. Repo public `ramaikn/koedu-crm` sudah dibuat, tapi belum di-push.

## Perubahan dan keputusan
- Kerangka dari `laravel/react-starter-kit` (Laravel 12, Inertia 2, React 19, Tailwind 4, TypeScript). `package-lock.json` dan workflow `.github` bawaan dibuang, karena dependensi frontend memakai bun (`bun.lock`).
- Layanan: app (php-fpm 8.4), web (nginx 1.30), worker, scheduler, vite (profil `dev`), db (Postgres 17), redis 8, n8n 2.47.2, waha (engine GOWS). Semua port hanya untuk 127.0.0.1.
- n8n memakai database Postgres terpisah (`n8n`), dibuat oleh skrip init.
- Container PHP tidak memakai `env_file`. Nilai env dibekukan saat container dibuat, sehingga APP_KEY kosong menimpa `.env` dan menyebabkan MissingAppKeyException.
- `vendor` disimpan di volume Docker, dan opcache aktif di dev. Waktu respons turun dari sekitar 3 detik ke sekitar 120 ms, karena bind mount dari disk Windows lambat untuk ribuan file.
- nginx memakai resolver DNS Docker, supaya tidak muncul 502 setelah container app dibuat ulang.
- Log Laravel ditulis ke `logs/` (channel daily, level info).

## Lokasi
- `compose.yaml`, `docker/php/Dockerfile`, `docker/php/php.ini`, `docker/nginx/default.conf`, `docker/postgres/init-n8n-db.sh`
- `.env.example`, `config/logging.php`, `vite.config.js`

## Verifikasi
- `/up`, `/`, dan `/login` membalas 200. n8n `/healthz` 200. WAHA `/ping` 200. Migrasi bawaan sukses.
- Setelah `app` di-restart, `/login` tetap 200 (resolver nginx bekerja).
- Belum diuji: profil `dev` (Vite hot reload) dan target image `prod`.
