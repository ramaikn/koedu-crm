# Uji ujung ke ujung: chat WA sampai jadwal, WA konfirmasi, dan Sheet

- Waktu: 2026-11-09 00:08 WITA (UTC+8)
- Jenis: test
- Ringkasan: alur otomatis diuji dengan layanan sungguhan memakai satu nomor uji milik user. Semua mata rantai yang dicoba berjalan.

## Pengaturan uji
- `NOTIFICATIONS_ENABLED=true`, `NOTIFY_ALLOWED_PHONES` dan `NOTIFY_ADMIN_PHONES` hanya berisi nomor uji user (di `.env` lokal, tidak masuk git). Nomor lain tidak dikirimi.

## Hasil
- Chat masuk ke WAHA, diteruskan n8n ke Laravel, lalu Gemini membuat draf jadwal (keyakinan 1.0, tanggal dan jam benar).
- Admin menyetujui draf lewat "Atur jadwal", jadwal tersimpan.
- Konfirmasi ke client terkirim ke WA saat sesi kurang dari 12 jam (melewati batas jam kirim sesuai aturan), isi benar (waktu WITA, tutor, metode).
- Tab Jadwal di Google Sheet bertambah dalam sekitar 10 detik, lalu disalin ulang setelah status konfirmasi berubah.

## Belum teramati
- Pesan ke tutor (nomor tutor contoh tidak ada di allowlist), pengingat H-1 dan 60 menit, ringkasan admin 08.00.
- Sebelum produksi: kosongkan `NOTIFY_ALLOWED_PHONES` dan ganti data contoh dengan data asli.
