# Skrip setup sekali jalan

- Waktu: 2026-11-09 21:35 (WITA)
- Jenis: feat
- Ringkasan: ditambah `scripts/setup.sh` agar proyek bisa langsung jalan setelah `git clone` di komputer baru (mis. komputer kantor saat tes).

## Perubahan
- `scripts/setup.sh`: cek Docker dan bun, buat `.env` dari `.env.example`, isi otomatis rahasia yang kosong (DB, WAHA, n8n) dengan nilai acak, build image, `composer install`, `up -d --wait`, `key:generate` hanya bila APP_KEY kosong, `migrate --seed`, `bun install` dan `bun run build`. Idempoten.
- `docs/agent/knowledge/menjalankan.md`: ditambah bagian cara cepat.

## Verifikasi
- Sintaks (`sh -n`) lolos; logika pengisi rahasia diuji di folder sementara.
- Alur lengkap (Docker) belum dijalankan ulang dari clone bersih. Coba sekali di mesin lain sebelum hari tes.
- Kunci Gemini, service account Google, dan WhatsApp tidak diisi otomatis; bawa sendiri bila perlu.
