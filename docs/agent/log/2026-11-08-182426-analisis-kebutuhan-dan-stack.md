# Analisis kebutuhan dan pemilihan stack

- Waktu: 2026-11-08 18:24 WITA (UTC+8)
- Jenis: analisis
- Ringkasan: kebutuhan CRM internal bimbingan skripsi dianalisis dari lowongan dan katalog produk publik. Stack dan integrasi sudah disepakati dengan user. WSL2 dan Docker Desktop terpasang, tinggal restart.

## Keputusan
- Laravel 12 + React (Inertia) + PostgreSQL, Docker Compose di lokal dan nanti di VPS Ubuntu terpisah (bukan VPS yang sudah dipakai aplikasi lain).
- WhatsApp: WAHA untuk simulasi, lewat adapter supaya bisa pindah ke Cloud API resmi. Dipilih karena lisensi Apache-2.0 dan sudah ada node n8n. open-wa masih aktif, tapi lisensinya khusus.
- AI: Google AI Studio (Gemini) di Laravel, dengan banyak API key dan fallback provider. AI hanya membuat draf, admin yang menyetujui.
- Google Sheets: cermin satu arah. Database tetap sumber utama.
- Pembayaran tanpa DP/cicilan, karena katalog publik tidak menyebutnya.
- Kelas grup (maksimal 20 peserta) masuk MVP.
- Hanya director yang mengelola akun.
- Repo public tanpa nama perusahaan. Nama tutor contoh diubah ejaannya atas keputusan user (sudah diberi tahu risikonya masih bisa dikenali). Kampus, prestasi, dan bio asli tidak dimasukkan.

## Lokasi
- `docs/agent/knowledge/kebutuhan-bisnis.md`, `docs/agent/knowledge/arsitektur.md`
- `docs/agent/task/2026-11-08-182426-feat-mvp-koedu-crm.md`

## Verifikasi dan keterbatasan
- `wsl --install --no-distribution` exit 0, dan winget melaporkan Docker Desktop 4.98.0 terpasang. Belum dicek setelah restart.
- Data paket dan tutor dibaca dari halaman publik pada 2026-11-08. Sebagian paket tidak mencantumkan harga, jadi harga contoh di seeder adalah perkiraan.
