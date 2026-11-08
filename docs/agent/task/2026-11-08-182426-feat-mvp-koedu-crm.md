# Task: MVP Koedu CRM

Acuan: [kebutuhan-bisnis.md](../knowledge/kebutuhan-bisnis.md), [arsitektur.md](../knowledge/arsitektur.md).

## Persiapan lingkungan
1. [x] Pasang WSL2 (`wsl --install --no-distribution`) dengan izin user dan pemilik PC
2. [x] Pasang Docker Desktop 4.98.0 lewat winget
3. [x] Jalankan Docker Desktop, cek `docker compose version` (Engine 29.9.2, Compose v5.6.1)
4. [x] Buat repo public `ramaikn/koedu-crm` lewat `gh`

## Kerangka
5. [x] Buat proyek Laravel 12 + React (Inertia) lewat container composer, frontend pakai bun
6. [ ] (berjalan) `compose.yaml`: app (php-fpm + nginx), worker queue, scheduler, postgres, redis, n8n, waha. Sudah jalan; healthcheck n8n, waha, worker, scheduler belum ada
7. [x] `.env.example` lengkap (driver WA, daftar key AI, kredensial Sheets, token n8n) tanpa nilai rahasia
8. [x] Log aplikasi ke `logs/` (level info/warn/error)

## Data dan role
9. [x] Migrasi: users (role), clients, tutors (bidang, jenjang, jam tersedia), packages, package_benefits, enrollments (pembayaran digabung, tanpa DP), enrollment_quotas, consultations, consultation_participants, wa_conversations, wa_messages, schedule_drafts
10. [x] Policy role: director, admin, tutor + middleware `role:`; registrasi publik ditutup, akun nonaktif ditolak login
11. [x] Seeder: katalog paket generik, 65 tutor (ejaan nama diubah, WA fiktif), client contoh
11a. [x] Tutup fitur hapus akun sendiri di halaman Settings bawaan starter kit (akun hanya dikelola director)

## Fitur
12. [x] CRUD client, tutor, paket (katalog), paket client (enrollment), pembayaran lunas dengan bukti bayar, koreksi kuota manual
12a. [ ] Kelola pengguna (director): buat akun admin/tutor, atur role, nonaktifkan, tautkan akun ke profil tutor. Saat ini akun hanya dibuat lewat seeder
12b. [ ] Tutor mengubah jam tersedianya sendiri (policy `updateAvailability` sudah ada, halamannya belum)
12c. [ ] Command terjadwal: paket yang lewat `ends_on` otomatis berstatus `expired`
13. [x] Jadwal: sesi privat dan grup (kapasitas), cek bentrok tutor dan client, peringatan jam/batas sesi/kuota/pembayaran, absensi, potong kuota saat selesai, ubah, batal
14. [x] Saran tutor: skor dari bidang, jenjang, pembimbing saat ini, jam tersedia, beban minggu itu, dengan alasan per tutor
15. [x] Adapter `WhatsAppProvider` (driver waha, cloud_api) + inbox WA (baca, balas). Template WA resmi (di luar 24 jam) belum ada
16. [ ] (berjalan) Adapter `AiProvider` (Gemini dengan rotasi key, cadangan OpenAI-compatible, fallback) + draf jadwal dari chat + persetujuan di inbox. Kode dan test selesai. Belum: uji dengan API key Gemini sungguhan (isi `GEMINI_API_KEYS`, jalankan `php artisan ai:check`, lalu kirim chat dari HP)
17. [ ] `SheetSync` lewat queue ke Google Sheets
18. [ ] (berjalan) API untuk n8n (token): webhook WA masuk (sudah), daftar reminder jatuh tempo, tandai terkirim (belum)
19. [ ] (berjalan) Workflow n8n (JSON di repo). Sudah: WA masuk ke Laravel. Belum: notif tutor saat jadwal disetujui, reminder H-1 dan 1 jam, paket hampir habis, pembayaran belum lunas
20. [ ] (berjalan) Dashboard director. Sudah: ringkasan harian (sesi hari ini, belum lunas, chat belum dibaca, client baru, paket hampir berakhir). Belum: grafik client baru, sesi per tutor, selesai/batal, pendapatan per bulan
21. [x] Halaman tutor: jadwal saya (daftar per hari), detail sesi, selesaikan sesi dengan absensi dan catatan

## Verifikasi
22. [ ] Unit test alur kritis: cek bentrok, potong kuota, saran tutor, rotasi key AI, parse webhook WA
23. [ ] Uji fungsional lewat `docker compose up`: chat simulasi WAHA sampai reminder terkirim
24. [ ] README cara menjalankan dan ganti ke WA resmi
