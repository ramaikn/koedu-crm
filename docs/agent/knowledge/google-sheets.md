# Google Sheets

Database aplikasi adalah sumber utama. Google Sheets hanya **salinan satu arah** untuk tim dan director. Edit manual di Sheet akan ditimpa.

## Cara kerja
```
perubahan data (jadwal, client, paket, tutor) --> SheetSyncObserver --> SyncSheetTabJob (antrean "sheets", jeda 10 detik, digabung)
n8n tiap malam 02.00 WITA --> POST /api/n8n/sheets/sync --> semua tab dijadwalkan (rekonsiliasi)
SyncSheetTabJob --> SheetSyncService --> tab.rows() --> GoogleSheetsClient.replaceTab() --> Google Sheets API v4
```
- Satu tab ditulis ulang utuh tiap sinkron: isi lama dihapus (`values:batchClear`), lalu catatan, judul kolom, dan data ditulis (`values:batchUpdate`, per 2000 baris). Sederhana, bisa diulang aman, dan otomatis membetulkan baris yang terlewat atau sudah terhapus.
- Job `ShouldBeUniqueUntilProcessing` per tab: beberapa perubahan beruntun cukup satu penulisan, dan perubahan yang terjadi saat job berjalan tetap membentuk satu job baru.
- Perubahan lewat query builder (bukan model) tidak memicu pengamat. Yang tercakup: kolom pesan otomatis (dipicu manual oleh `NotificationDispatcher` bila ada yang terkirim) dan sisanya dibetulkan rekonsiliasi malam.
- Tab baru dibuat otomatis (baris judul dibekukan, ditebalkan, dan diberi peringatan "salinan otomatis" yang hanya memperingatkan saat diedit). Tab lain yang dibuat tim di spreadsheet yang sama tidak disentuh, jadi aman dipakai untuk analisis sendiri.

## Tab
| Tab | Baris | Isi utama |
|---|---|---|
| Jadwal | per sesi (12 bulan terakhir sampai semua yang akan datang) | tanggal, hari, jam, judul, jenis, tutor, client, status, kehadiran, tutor diberi tahu, client dikonfirmasi |
| Client | per client (tanpa yang dihapus) | nama, **nomor WhatsApp lengkap**, email, kampus, jurusan, bidang, jenjang, status, jumlah paket aktif, sesi selesai dan mendatang |
| Paket Client | per paket yang dibeli | client, paket, masa aktif, sisa hari, harga, status bayar, tanggal dan metode bayar, tutor, kuota terpakai |

Tidak disalin: catatan internal client, catatan tutor, alasan pembatalan, bukti bayar. Baris 1 = catatan, baris 2 = judul kolom, data mulai baris 3. Jangan menyisipkan kolom atau baris di tab ini.
Tab baru: satu kelas `SheetTab` di `app/Services/Sheets/Tabs`, daftarkan di `SheetTabs`, dan petakan model pemicunya di `SheetSyncObserver::TABS_BY_MODEL`.

## Keamanan
- Sel dituliskan dengan `USER_ENTERED` (supaya tanggal dan angka dikenali), yang juga membaca rumus. Karena itu `Cell::safe()` memberi apostrof pada teks yang diawali `=`, `+`, `-`, atau `@` (nama client `=HYPERLINK(...)` tetap teks). Nomor telepon memakai `Cell::text()` supaya tidak menjadi angka ilmiah.
- Sheet memuat nomor WhatsApp lengkap semua client (keputusan user). Bagikan hanya ke akun yang perlu, jangan "siapa pun dengan tautan".
- File kunci akun layanan = kredensial. Disimpan di `storage/app/private/` (otomatis tidak ikut git). Jangan dibagikan atau ditempel di chat.
- Token akses dibuat dari JWT RS256 yang ditandatangani di aplikasi (tanpa pustaka Google), disimpan di cache sekitar 50 menit.

## Gangguan dan pemulihan
- 429 dan 5xx dan gangguan jaringan = sementara: dicoba ulang 4 kali (jeda 30, 120, 300 detik). Status tab di dashboard: "tertunda, akan dicoba lagi".
- 401, 403, 404, dan kunci salah = pengaturan: berhenti, tanpa mengulang, dashboard menampilkan peringatan ramah. Detail ada di `logs/` (403: "Bagikan Sheet ke <email akun layanan> sebagai Editor").
- Batas Google: sekitar 60 penulisan per menit per pengguna dan 10 juta sel per file. Jeda 10 detik dan penggabungan job menjaga di bawah batas. Batas baris per tab: `GOOGLE_SHEETS_MAX_ROWS` (20000).
- `consultations.sheet_synced_at` ditandai lewat query langsung setelah tab Jadwal tersalin, tanpa mengubah `updated_at` (kalau tidak, sinkron akan memicu dirinya sendiri).

## Menyiapkan (sekali saja)
1. Buka https://console.cloud.google.com dan buat proyek (mis. `koedu-crm`).
2. Menu **APIs & Services > Library**, cari **Google Sheets API**, klik **Enable**.
3. **IAM & Admin > Service Accounts > Create service account**. Nama bebas (mis. `koedu-sync`). Peran tidak perlu diisi. Selesai.
4. Buka akun layanan itu, tab **Keys > Add key > Create new key > JSON**. File JSON terunduh.
5. Pindahkan file itu ke `koedu-crm/storage/app/private/` dan ubah namanya menjadi `google-service-account.json`.
6. Buat Google Sheet baru yang kosong. Klik **Share**, tambahkan alamat email akun layanan (ada di file JSON pada baris `client_email`, berakhiran `iam.gserviceaccount.com`) sebagai **Editor**. Matikan "Notify people".
7. Salin ID Sheet dari alamatnya: `docs.google.com/spreadsheets/d/<ID INI>/edit`.
8. Di `.env`: `GOOGLE_SHEETS_ENABLED=true` dan `GOOGLE_SHEETS_SPREADSHEET_ID=<ID>`.
9. `docker compose restart app worker scheduler`
10. `docker compose exec app php artisan sheets:check` (memeriksa kunci, akses, tab), lalu `docker compose exec app php artisan sheets:sync --now` untuk penyalinan pertama.
11. `sh scripts/n8n-import.sh` bila workflow rekonsiliasi malam belum diimpor.

Bila Google melarang membuat kunci ("Service account key creation is disabled"), kebijakan organisasi akun Google Workspace yang memblokirnya. Gunakan akun Google pribadi untuk proyek ini, atau minta administrator mengizinkan.

## Perintah
- `php artisan sheets:check`: periksa penyiapan dan beri petunjuk perbaikan (kunci tidak ditampilkan).
- `php artisan sheets:sync [jadwal|client|paket] [--now]`: salin sekarang (`--now` langsung dan menampilkan hasil, tanpa itu lewat antrean).
