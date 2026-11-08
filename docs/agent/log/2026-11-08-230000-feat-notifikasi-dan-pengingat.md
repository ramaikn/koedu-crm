# Notifikasi tutor, reminder client, dan ringkasan admin

- Waktu: 2026-11-08 23:00 WITA (UTC+8)
- Jenis: feat
- Ringkasan: mesin notifikasi di Laravel (aturan, jam kirim, klaim atomik, pengaman nomor), workflow n8n pemicu tiap 5 menit, ringkasan harian admin, kartu status di detail jadwal, kartu pesan gagal di dashboard, dan tombol kirim ulang di inbox sudah dibuat. 242 test lulus. Belum diuji kirim sungguhan ke WhatsApp.

## Keputusan (dipilih user)
- n8n sebagai pemicu, Laravel sebagai pengirim (adapter WA, log, anti ganda, mudah pindah ke WA resmi).
- Tutor: kabar saat jadwal dibuat/diubah/dialihkan/dibatalkan, pengingat sebelum sesi. Client: konfirmasi, perubahan, pembatalan, H-1 pukul 18.00, dan sebelum sesi. Jendela kirim 07.00-21.00 WITA, ditunda ke pagi kecuali sesi dalam 12 jam.
- Pengingat paket hampir habis dan belum lunas hanya untuk admin (ringkasan harian pukul 08.00), tanpa tagihan otomatis ke client.

## Hal yang dirancang dengan sengaja
- Bawaan mati (`NOTIFICATIONS_ENABLED=false`) plus daftar nomor yang diizinkan, karena data demo berisi nomor palsu dan dry run membuktikan akan ada puluhan pesan ke nomor itu.
- Aturan hanya menyusun rencana; klaim atomik per pesan mencegah ganda walau dua proses jalan bersamaan. Klaim dan antre satu transaksi, jadi kegagalan antre membatalkan klaim.
- Pengingat yang sudah tidak berguna dilewati (jadwal baru dibuat sesaat sebelum, sesi hari ini tidak diingatkan "besok", terlalu dekat dengan sesi).
- Perubahan diumumkan dengan jam lama dan baru. Perubahan yang kembali ke waktu semula tidak dikirim. Pergantian tutor memberi tahu tutor lama, tutor baru, dan client.
- Alasan pembatalan bersifat internal dan tidak pernah dikirim.
- Dispatcher dan pengirim WA berkelas `final`, jadi test kegagalan memakai penyedia WA yang melempar error, bukan mock.

## Celah yang ditemukan saat merancang test dan ditutup
- Pergantian tutor tanpa perubahan waktu tidak akan diberitahukan ke client (jamnya sama). Ditambahkan `confirmed_tutor_id` dan pesan "tutor diganti".
- Pengiriman job di dalam transaksi luar bisa berjalan sebelum commit. `SendWhatsAppMessage` kini memakai `afterCommit`.

## Lokasi
- `app/Services/Notifications/*` (rules, window, templates, dispatcher), `app/Console/Commands/NotificationsRunCommand.php`
- `app/Http/Controllers/Api/N8nNotificationController.php`, `routes/api.php`, `n8n/workflows/notifikasi-berkala.json`
- Migrasi `2026_11_08_230000_add_notification_tracking_columns.php`, `config/koedu.php` (bagian `notifications`), `.env.example`
- `InboxController::retry`, `DashboardController`, `ScheduleController::show`; `resources/js/pages/{dashboard,inbox/index,schedule/show}.tsx`
- `tests/Feature/Notifications/*`

## Verifikasi
- `php artisan test`: 242 lulus (41 test notifikasi: alur sesi, pengingat, jam kirim, pengaman, klaim, API, perintah, ringkasan, template). `tsc`, `bun run build`, lint lulus.
- `notifications:run --dry-run` pada data demo menampilkan pesan yang rapi untuk puluhan sesi mendatang.
- Impor workflow n8n berhasil (2 workflow aktif). Tampilan dicek di browser: kartu "Pesan otomatis" di detail jadwal dan enam kartu dashboard.
- Belum: kirim sungguhan ke WhatsApp, dan pemicu terjadwal n8n yang sampai ke Laravel (lihat log berikutnya bila ditambahkan). Ketepatan pesan terhadap kebiasaan tim belum ditinjau tim bisnis.
