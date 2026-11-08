# Notifikasi dan Pengingat WhatsApp

## Alur
```
n8n (workflow "Notifikasi dan pengingat", tiap 5 menit)
  --> POST /api/n8n/notifications/run (token)
  --> NotificationDispatcher: aturan menyusun daftar pesan --> filter nomor, jam kirim --> klaim + antre (satu transaksi)
  --> SendWhatsAppMessage --> antrean "whatsapp" --> driver WA (WAHA / Cloud API)
```
- n8n hanya pemicu. Semua keputusan, pengiriman, log, dan pencegahan pesan ganda ada di Laravel. Mengganti WAHA ke WA resmi tidak mengubah workflow.
- Pemicu alternatif: `php artisan notifications:run`. Mode uji: `--dry-run` menampilkan pesan dan penerimanya (nomor disamarkan) tanpa mengirim atau mencatat apa pun, walau notifikasi sedang mati.
- API memakai kunci cache `notifications:run` (120 detik), jadi dua pemicu yang tumpang tindih tidak berjalan bersamaan.

## Pengaman (penting)
- `NOTIFICATIONS_ENABLED=false` bawaan. Data demo memakai nomor palsu; mengirim ke nomor yang tidak ada lewat WAHA berisiko membuat nomor WA diblokir.
- `NOTIFY_ALLOWED_PHONES` (62xxx, koma): bila diisi, pesan hanya dikirim ke nomor itu, dan pesan lain tidak diklaim (terkirim begitu nomornya diizinkan). Pakai ini untuk uji dengan nomor sendiri. Format lokal 0812.. juga cocok.
- Klaim bersifat atomik per pesan (`UPDATE ... WHERE kolom_penanda IS NULL`, hanya satu pemanggil yang mendapat 1 baris). Klaim dan antre pesan satu transaksi: bila antre gagal, klaim batal dan dicoba lagi di putaran berikutnya. Pengiriman job memakai `afterCommit` supaya worker tidak mencari pesan yang belum tersimpan.

## Aturan (`app/Services/Notifications/Rules`)
| Pesan | Penerima | Kapan |
|---|---|---|
| Jadwal baru | Tutor, client (konfirmasi) | Sesi terjadwal, belum pernah diberi tahu |
| Jadwal diubah | Tutor, client | Waktu berubah: pesan memuat jam lama dan baru. Waktu kembali ke yang sudah diketahui: dicatat tanpa pesan |
| Tutor diganti | Tutor lama ("dialihkan"), tutor baru ("jadwal baru"), client ("tutor diganti dari X") | Tutor berubah. Bila hanya tutor yang berubah, client tetap diberi tahu |
| Dibatalkan | Tutor dan client yang pernah diberi tahu | Alasan pembatalan (internal) tidak pernah dikirim |
| Pengingat H-1 | Client | Hari sebelum sesi pukul 18.00 (`NOTIFY_DAY_REMINDER_TIME`). Tidak untuk sesi hari ini |
| Pengingat sebelum sesi | Client dan tutor | 60 menit sebelum (`NOTIFY_HOUR_REMINDER_MINUTES`) |
| Ringkasan harian | Admin | Pukul 08.00 (`NOTIFY_DIGEST_TIME`): sesi hari ini, paket berakhir 7 hari, belum lunas lebih dari 3 hari, draf menunggu, pesan gagal. Tidak dikirim bila kosong |

Pengingat dilewati (tidak dikirim dan tidak dicatat) bila: kabar jadwal dikirim setelah waktu pengingat, sisa waktu kurang dari `NOTIFY_MIN_LEAD_MINUTES` (10), sesi dibatalkan, atau sesi sudah lewat.
Tidak ada tagihan otomatis ke client. Pengingat paket dan pembayaran hanya masuk ringkasan admin.

## Jam kirim
Pesan hanya dikirim 07.00-21.00 WITA (`NOTIFY_WINDOW_START/END`). Di luar jam itu pesan ditunda sampai pagi, kecuali sesinya dalam 12 jam ke depan (`NOTIFY_URGENT_HOURS`): perubahan atau pembatalan mendadak tetap dikirim.

## Data pelacak (di tabel yang sudah ada)
- `consultations`: `tutor_notified_at` (kosong = perlu diberi tahu), `tutor_notified_starts_at` dan `tutor_notified_tutor_id` (apa yang terakhir diberitahukan), `tutor_cancel_notified_at`, `tutor_reminded_at`.
- `consultation_participants`: `confirmation_sent_at`, `confirmed_starts_at`, `confirmed_tutor_id`, `cancel_notified_at`, `reminder_day_sent_at`, `reminder_hour_sent_at`.
- `ScheduleConsultation` mengosongkan penanda kirim saat waktu atau tutor berubah, tetapi tidak menyentuh kolom "apa yang terakhir diberitahukan", sehingga perubahan bisa diumumkan dengan jam lama.
- "Pernah diberi tahu" ditentukan oleh `*_starts_at` yang terisi, bukan `*_at`. Data demo bawaan belum punya penanda ini, jadi dianggap belum pernah diberi tahu.

## Tampilan
- Detail jadwal: kartu "Pesan otomatis" (kabar jadwal dan pengingat per tutor dan client).
- Dashboard: kartu "Pesan gagal terkirim (24 jam)". Inbox: tombol "Kirim ulang" di pesan gagal (admin, `POST /inbox/messages/{id}/retry`).

## Mengubah isi pesan
Semua teks ada di `MessageTemplates`. Nama pengirim di penutup pesan dari `NOTIFY_SENDER_NAME`. Format waktu: "Selasa, 10 November 2026 pukul 14.00-15.30 WITA" (zona waktu dari `APP_TIMEZONE`).

## Menyalakan
1. Uji dengan nomor sendiri: isi `NOTIFY_ALLOWED_PHONES` dan `NOTIFY_ADMIN_PHONES`, set `NOTIFICATIONS_ENABLED=true`, `docker compose restart app worker scheduler`.
2. `docker compose exec app php artisan notifications:run --dry-run` untuk melihat rencana, lalu biarkan n8n memicu (impor: `sh scripts/n8n-import.sh`).
3. Produksi: kosongkan `NOTIFY_ALLOWED_PHONES`. Pada WA resmi, pesan di luar jendela 24 jam butuh template Meta (belum dibuat), jadi pengingat ke client yang lama tidak chat akan ditolak.
