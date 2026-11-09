# Kelola pengguna, jam tersedia tutor, paket kedaluwarsa otomatis

- Waktu: 2026-11-09 21:21 WITA (+08:00)
- Jenis: feat
- Ringkasan: menutup task 12a, 12b, 12c. Director kini bisa mengelola akun dari aplikasi, tutor bisa mengatur jam tersedianya sendiri, dan paket yang lewat masa aktif otomatis berubah status.

## Perubahan dan keputusan
- Halaman `/users` (khusus director): daftar dengan filter, buat akun, ubah role, nonaktifkan, ganti password. Akun tidak dihapus supaya riwayat tetap utuh.
- Akun role tutor wajib ditautkan ke profil tutor yang belum punya akun. Ganti role ke selain tutor melepas tautannya.
- Director tidak bisa mengubah role atau menonaktifkan akunnya sendiri (validasi server, isian dikunci di layar).
- Middleware `LogoutInactiveUser` di grup web: akun yang dinonaktifkan langsung keluar pada permintaan berikutnya, termasuk sesi "ingat saya". Sebelumnya hanya login baru dan route ber-`role:` yang menolak.
- Halaman `/my-availability` untuk tutor. Validasi jam dipindah ke trait `ValidatesAvailabilities`, editor ke komponen `availability-editor.tsx`, dipakai juga oleh form tutor milik admin.
- Command `enrollments:expire`, dijadwalkan tiap 00:05 di `routes/console.php`. Diubah per model supaya salinan Google Sheets ikut terpicu.

## Berkas terkait
- `app/Http/Controllers/UserController.php`, `app/Http/Requests/UserRequest.php`, `app/Http/Middleware/LogoutInactiveUser.php`
- `app/Http/Controllers/MyAvailabilityController.php`, `app/Http/Requests/AvailabilityRequest.php`, `app/Http/Requests/Concerns/ValidatesAvailabilities.php`
- `app/Console/Commands/ExpireEnrollmentsCommand.php`, `routes/console.php`
- `resources/js/pages/users/`, `resources/js/pages/my-availability.tsx`, `resources/js/components/availability-editor.tsx`

## Verifikasi
- `tests/Feature/Pages/UserAndAvailabilityPagesTest.php` (10 test) dan test halaman tutor lulus. Putaran penuh sebelum perbaikan terakhir: 298 lulus, 1 gagal (`email_verified_at` tidak tersimpan, sudah diperbaiki dan diuji ulang).
- `tsc --noEmit` dan `bun run build` bersih. `schedule:list` menampilkan jadwal 00:05.
- `enrollments:expire` dijalankan di data demo lokal: 13 paket menjadi kedaluwarsa.
- Belum dicek visual di browser.
