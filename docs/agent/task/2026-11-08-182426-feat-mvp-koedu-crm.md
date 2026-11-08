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
12. [ ] CRUD client, tutor, paket, enrollment, pembayaran (lunas/belum, bukti transfer)
13. [ ] (berjalan) Jadwal: sesi privat dan grup (kapasitas 20), cek bentrok tutor, absensi, potong kuota saat selesai. Sudah: `CompleteConsultation` (absensi + potong kuota). Belum: buat/ubah jadwal + cek bentrok
14. [ ] Saran tutor: skor dari bidang, jenjang, jam kosong, beban kerja
15. [ ] Adapter `WhatsAppProvider` (driver waha, cloud_api) + inbox WA (baca, balas)
16. [ ] Adapter `AiProvider` (driver gemini, rotasi key saat 429, fallback provider) + draf jadwal dari chat
17. [ ] `SheetSync` lewat queue ke Google Sheets
18. [ ] API untuk n8n (token): webhook WA masuk, daftar reminder jatuh tempo, tandai terkirim
19. [ ] Workflow n8n (JSON di repo): webhook WA ke Laravel, notif tutor saat jadwal disetujui, reminder H-1 dan 1 jam, paket hampir habis, pembayaran belum lunas
20. [ ] Dashboard director: client baru, sesi per tutor, selesai/batal, pendapatan per bulan
21. [ ] Halaman tutor: jadwal saya, catatan sesi

## Verifikasi
22. [ ] Unit test alur kritis: cek bentrok, potong kuota, saran tutor, rotasi key AI, parse webhook WA
23. [ ] Uji fungsional lewat `docker compose up`: chat simulasi WAHA sampai reminder terkirim
24. [ ] README cara menjalankan dan ganti ke WA resmi
