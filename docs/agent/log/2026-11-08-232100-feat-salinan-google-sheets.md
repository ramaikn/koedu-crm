# Salinan satu arah ke Google Sheets

- Waktu: 2026-11-08 23:21 WITA (UTC+8)
- Jenis: feat
- Ringkasan: salinan otomatis jadwal, client, dan paket client ke Google Sheets lewat akun layanan, dengan pemicu perubahan data, rekonsiliasi malam dari n8n, perintah pemeriksaan, dan kartu status di dashboard. 289 test lulus. Belum diuji ke Google sungguhan karena butuh akun layanan dan Sheet milik user.

## Keputusan (dipilih user)
- Tab: Jadwal, Client, Paket Client. Nomor WhatsApp client ditampilkan lengkap (user memilih ini; risikonya dicatat di dokumentasi). Pembaruan otomatis dalam hitungan detik ditambah rekonsiliasi malam.
- Database tetap sumber utama; Sheet hanya salinan yang ditimpa tiap sinkron.

## Hal yang dirancang dengan sengaja
- Satu tab ditulis ulang utuh (hapus isi lalu tulis), bukan upsert per baris: idempotent, membetulkan baris terlewat atau terhapus, dan tanpa pencarian posisi baris. Cukup murah di skala bisnis ini; dipecah per 2000 baris.
- Job unik sampai mulai diproses, dengan jeda 10 detik: perubahan beruntun digabung dan penulisan tetap di bawah batas kuota Google.
- Rumus dicegah: nama atau judul yang diawali `=`, `+`, `-`, `@` diberi apostrof; nomor telepon dipaksa teks.
- Kegagalan dibedakan: sementara (kuota, jaringan, server) diulang 4 kali; pengaturan (401, 403, 404, kunci salah) berhenti dan tampil sebagai peringatan ramah di dashboard dengan petunjuk perbaikan di log dan `sheets:check`.
- Klien Google dibuat sendiri (JWT RS256 dengan OpenSSL dan HTTP client Laravel) tanpa pustaka Google yang berat.
- Penandaan `sheet_synced_at` lewat query langsung tanpa mengubah `updated_at`, supaya sinkron tidak memicu dirinya sendiri.
- Pesan otomatis (kolom "diberi tahu") berubah lewat query langsung, jadi `NotificationDispatcher` memicu sinkron Jadwal secara eksplisit.

## Lokasi
- `app/Services/Sheets/*` (klien, kunci, sel, tab, layanan, status, pemicu), `app/Jobs/SyncSheetTabJob.php`, `app/Observers/SheetSyncObserver.php`
- `app/Console/Commands/Sheets{Check,Sync}Command.php`, `app/Http/Controllers/Api/N8nSheetsController.php`, `n8n/workflows/sheets-rekonsiliasi.json`
- `config/koedu.php` (bagian `sheets`), `.env.example`, `phpunit.xml`, kartu di `resources/js/pages/dashboard.tsx`
- `tests/Feature/Sheets/*`

## Verifikasi
- `php artisan test`: 289 lulus (47 test Sheets: JWT diverifikasi dengan kunci RSA sungguhan, bentuk permintaan API, pemetaan kesalahan, pemetaan tab, pemicu, API, perintah, dashboard). `tsc`, build, dan lint lulus. Tiga workflow n8n aktif.
- Semua panggilan Google dipalsukan di test. Belum terbukti: bentuk permintaan terhadap Google sungguhan (nama field `batchClear`, `batchUpdate`, `protectedRange` sesuai dokumentasi API, tetapi baru bisa dipastikan lewat `sheets:check` dan `sheets:sync --now` setelah kunci dipasang).
- Belum dicek secara visual: kartu Google Sheets di dashboard (hanya muncul saat aktif).
