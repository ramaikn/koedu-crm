# Jadwal, saran tutor, dan halaman aplikasi

- Waktu: 2026-11-08 21:17 WITA (UTC+8)
- Jenis: feat
- Ringkasan: aturan jadwal (bentrok dan peringatan), saran tutor, dan halaman Dashboard, Jadwal, Client, Tutor, Paket sudah dibuat, lengkap dengan paket client, pembayaran, dan kuota. 154 test lulus. Tampilan dicek di browser dengan akun admin contoh.

## Keputusan (dipilih user)
- Halaman Jadwal berupa daftar per hari dengan filter tanggal, tutor, status, dan pencarian.
- Bentrok tutor atau client diblokir. Di luar jam tersedia, melewati batas sesi mingguan, client tanpa paket/kuota, dan paket belum lunas hanya peringatan yang harus dikonfirmasi admin.
- Tidak ada DP atau cicilan: satu paket, satu pembayaran, dengan bukti bayar opsional (JPG/PNG/PDF maks 5 MB, disk privat).
- Katalog paket hanya diubah director. Perubahan tidak memengaruhi paket yang sudah dibeli (harga dan kuota sudah disalin).
- Tutor melihat client bimbingannya tanpa data keuangan, dan hanya bisa menyelesaikan sesi setelah waktunya mulai.

## Bug yang ditemukan lewat test dan diperbaiki
- Jam tersedia dibandingkan sebagai teks `H:i:s`; nilai `12:00` (tanpa detik) dianggap lebih kecil dari `12:00:00`, sehingga sesi yang berakhir tepat di batas jam dianggap di luar jam tersedia. Diganti `Tutor::isAvailableBetween()` yang membandingkan `H:i`.
- Daftar "cocok tapi bentrok" memakai skor total, sehingga tutor tidak relevan ikut tampil. Sekarang memakai penanda `relevant` (bidang cocok atau pembimbing client).
- Pencarian client yang hanya berisi huruf cocok dengan semua nomor (`like '%%'`), dan nomor format lokal 0812 tidak menemukan 62812. Diperbaiki dengan `Client::matching`.
- Sesi baru tidak membawa status di memori sampai dimuat ulang. Status diisi eksplisit saat dibuat.
- Ikon Laravel di logo diganti ikon aplikasi.

## Lokasi
- `app/Services/Scheduling/*`, `app/Actions/Consultations/{ScheduleConsultation,CancelConsultation}.php`
- `app/Http/Controllers/{Schedule,Client,Enrollment,Tutor,Package,Dashboard}Controller.php`, `app/Http/Requests/*`
- `resources/js/pages/{schedule,clients,tutors,packages}/*`, `resources/js/pages/dashboard.tsx`, komponen bersama di `resources/js/components` dan `resources/js/lib`
- `tests/Feature/Scheduling/*`, `tests/Feature/Pages/*`

## Verifikasi
- `php artisan test`: 154 lulus. `tsc --noEmit`, `bun run build`, dan lint lulus.
- Di browser (admin): dashboard, daftar jadwal, form jadwal dengan saran tutor dan peringatan, pembuatan sesi, pembatalan dengan alasan, detail client dengan paket dan kuota. Halaman tutor, paket, dan form client dimuat tanpa error JavaScript.
- Belum dicek secara visual: tampilan HP, mode gelap, halaman sebagai tutor dan director, form tutor dan paket (hanya diuji lewat test).
- Belum dibuat: kelola pengguna oleh director, tutor mengubah jam tersedia sendiri, command paket kedaluwarsa otomatis, grafik dashboard director (lihat checklist).
