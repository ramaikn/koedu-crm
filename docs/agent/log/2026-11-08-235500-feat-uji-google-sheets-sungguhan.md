# Uji Google Sheets sungguhan

- Waktu: 2026-11-08 23:55 WITA (UTC+8)
- Jenis: feat
- Ringkasan: Google Sheets disiapkan dan diuji ke Google sungguhan. Salinan pertama berhasil tanpa perubahan kode.

## Yang dilakukan
- Proyek Google Cloud `koedu-crm`, Sheets API diaktifkan, akun layanan `koedu-sync` dibuat, kunci JSON dipindah ke `storage/app/private/google-service-account.json` (tidak ikut git, isinya tidak dibaca agen).
- Sheet "Koedu CRM (salinan otomatis)" dibuat dan dibagikan ke akun layanan sebagai Editor.
- `.env` lokal: `GOOGLE_SHEETS_ENABLED=true` dan ID Sheet terisi.

## Hasil
- `sheets:check`: akses berhasil. `sheets:sync --now`: jadwal 139 baris, client 46, paket 40.
- Tab Jadwal, Client, Paket Client dibuat otomatis, berikon kunci (peringatan edit), baris catatan dan judul tampil benar. Tanggal dan jam dikenali sebagai tanggal/waktu.
- Belum dicek: kartu status Sheets di dashboard, pembaruan otomatis lewat antrean setelah ubah data, workflow rekonsiliasi malam di waktu aslinya.
