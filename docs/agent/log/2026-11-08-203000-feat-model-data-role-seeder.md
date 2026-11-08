# Model data, role, dan seeder

- Waktu: 2026-11-08 20:30 WITA (UTC+8)
- Jenis: feat
- Ringkasan: skema database CRM, enum, model, policy tiga role, action enrollment dan penyelesaian sesi, serta seeder katalog paket, 65 tutor, dan data demo sudah dibuat. 41 test lulus.

## Keputusan
- Pembayaran disimpan langsung di `enrollments` (tanpa tabel payments), karena tidak ada DP/cicilan.
- Sesi privat dan grup memakai satu tabel `consultations` + `consultation_participants`. Nama `sessions` dihindari karena sudah dipakai Laravel.
- Harga dan kuota disalin ke enrollment saat beli, supaya perubahan katalog tidak mengubah paket berjalan.
- Kuota client yang tidak hadir tidak dipotong secara default (`QUOTA_DEDUCT_ON_ABSENT`). Ini kebijakan bisnis yang perlu dikonfirmasi.
- Katalog paket dikelola director, admin hanya melihat. Director hanya membaca data operasional.
- Registrasi publik dan halaman welcome dihapus. `/` diarahkan ke dashboard.
- Nama tutor contoh diubah ejaannya dan tanpa gelar. Nomor WA tutor dan client fiktif (rentang 62800).
- Koneksi Postgres memakai zona waktu `APP_TIMEZONE`. Sebelumnya jam sesi bergeser 8 jam (09:00 WITA tersimpan sebagai 17:00 WITA).

## Lokasi
- `app/Enums`, `app/Models`, `app/Policies`, `app/Http/Middleware/EnsureUserHasRole.php`
- `app/Actions/Enrollments/CreateEnrollment.php`, `app/Actions/Consultations/CompleteConsultation.php`, `app/Support/PhoneNumber.php`
- `config/koedu.php`, `config/database.php` (timezone pgsql)
- `database/migrations/2026_11_08_1000*`, `database/seeders` (+ `data/`), `database/factories`
- `tests/Feature/Domain`, `tests/Unit/PhoneNumberTest.php`

## Verifikasi
- `migrate:fresh --seed` sukses: 68 user, 65 tutor, 52 bidang, 27 paket, 45 client, 39 enrollment, 143 sesi (85 selesai, 58 mendatang), 0 jadwal tutor bentrok, 0 sesi di luar jam tersedia.
- `php artisan test`: 41 lulus. `tsc --noEmit` dan `bun run build` lulus.
- Belum: halaman UI untuk data baru. Fitur hapus akun sendiri bawaan starter kit masih aktif (dicatat di checklist 11a).
