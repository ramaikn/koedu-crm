# Penjadwalan, Saran Tutor, dan Halaman

## Aturan jadwal (`app/Services/Scheduling/ScheduleChecker.php`)
Satu pemeriksa untuk form admin, endpoint pemeriksaan langsung, dan nanti persetujuan draf AI.

| Jenis | Kondisi | Akibat |
|---|---|---|
| Error | Tutor atau client sudah punya sesi yang beririsan (sesi berurutan tanpa jeda tidak dianggap bentrok, sesi batal diabaikan) | Ditolak |
| Error | Client kosong, sesi privat lebih dari satu client, peserta melebihi kapasitas, tutor nonaktif, jam selesai tidak setelah jam mulai | Ditolak |
| Warning | Di luar jam tersedia tutor (harus muat seluruhnya dalam satu rentang di hari yang sama) | Boleh lanjut setelah dikonfirmasi |
| Warning | Tutor melewati `max_weekly_sessions` pada minggu itu (Senin-Minggu) | idem |
| Warning | Client tanpa paket aktif dengan sisa kuota layanan itu pada tanggal sesi, atau paketnya belum lunas | idem |

- `ScheduleConsultation` (action) mengunci baris tutor selama cek dan simpan, jadi dua admin tidak bisa memesan slot yang sama bersamaan. Error tidak bisa dilewati, warning wajib `confirm_warnings=true`.
- Mengubah waktu atau tutor mengosongkan penanda notifikasi dan pengingat (tutor dan client diberi tahu ulang). Setiap perubahan mengosongkan `sheet_synced_at`.
- Hanya sesi berstatus terjadwal yang bisa diubah atau dibatalkan. Pembatalan butuh alasan dan tidak memotong kuota.
- `EnrollmentResolver`: paket dipilih yang aktif, berlaku pada tanggal sesi, dan masih punya sisa kuota; bila beberapa, yang paling dulu berakhir.
- Sesi baru wajib di masa depan. Sesi hanya bisa diselesaikan setelah waktu mulai (mencegah kuota terpotong untuk sesi yang belum terjadi).
- Jam dibandingkan dalam format `H:i` lewat `Tutor::isAvailableBetween()`, bukan sebagai teks `H:i:s`.

## Saran tutor (`TutorSuggester`)
Skor = bidang (sama persis 50, satu rumpun 25) + pembimbing client saat ini 20 + jenjang cocok 10 + jam tersedia 25 / di luar jam -25 + bonus beban rendah (maks 15). Bobot ada di konstanta kelas.
- Client S3 cocok dengan tutor S3; client S2 cocok dengan S2 dan S3.
- Tutor yang sudah punya sesi di jam itu tidak masuk peringkat. Yang relevan (bidang cocok atau pembimbing client) ditampilkan terpisah sebagai "cocok tapi bentrok".
- Hasil: maksimal 8 tutor kosong + 3 yang bentrok, masing-masing dengan daftar alasan.

## Endpoint JSON untuk form (hanya admin)
- `POST /schedule/check`: `{ready, errors[], warnings[]}`. `ready=false` bila isian belum lengkap.
- `POST /schedule/suggest-tutors`: `{eligible[], blocked[]}`.
- `GET /clients/search?q=`: pemilih client (staf).

## Halaman dan hak akses
| Halaman | Admin | Director | Tutor |
|---|---|---|---|
| Dashboard | Ringkasan operasional | idem | Jadwal sendiri |
| Jadwal (`/schedule`) | Semua, buat/ubah/batal | Semua, baca | Hanya sesinya, selesaikan |
| Client | Kelola, paket, pembayaran, kuota | Baca (termasuk keuangan) | Hanya client bimbingannya, tanpa data keuangan |
| Tutor | Kelola profil dan jam tersedia | idem | Tidak ada |
| Paket (katalog) | Lihat | Kelola | Tidak ada |
| Inbox WA | Baca dan balas | Baca | Tidak ada |
| Jam tersedia (`/my-availability`) | Tidak ada (lewat form Tutor) | Tidak ada | Ubah jam sendiri |
| Pengguna (`/users`) | Tidak ada | Buat akun, role, nonaktifkan, reset password, tautkan profil tutor | Tidak ada |

- Bukti bayar disimpan di disk privat (`storage/app/private/payment-proofs`) dan diunduh lewat route bersesi, hanya staf.
- Pencarian client (`Client::matching`): nama, email, universitas, atau nomor (format lokal 0812.. cocok dengan 62812..). Nomor hanya dicari bila yang diketik minimal 3 angka.
- Filter halaman daftar tersimpan di URL (`useFilters`). Semua jam di layar memakai zona waktu kantor (`timezone` dari server).
- Pesan sukses lewat `->with('success', ...)` dan tampil sebagai banner sementara (`FlashMessage`).
