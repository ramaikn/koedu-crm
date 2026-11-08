# Kebutuhan Bisnis

Aplikasi internal untuk bisnis bimbingan skripsi, tesis, disertasi, penelitian, dan beasiswa. Hanya dipakai staf (admin, tutor, director). Client (mahasiswa) tidak login; mereka berinteraksi lewat WhatsApp.

## Masalah yang diselesaikan
1. Jadwal konsultasi diatur manual lewat chat WA, rawan bentrok dan lupa.
2. Memilih tutor yang cocok dari puluhan tutor (sekitar 65) lintas bidang.
3. Notifikasi ke tutor dan pengingat ke client dikirim manual.
4. Kuota layanan per paket (sesi Zoom, Turnitin, simulasi sidang) sulit dilacak.
5. Director tidak punya gambaran cepat soal client, beban tutor, dan pendapatan.

## Role
| Role | Boleh |
|---|---|
| Director | Semua akses baca, dashboard dan laporan, satu-satunya yang mengelola akun pengguna dan role |
| Admin | Kelola client, paket client, pembayaran, jadwal, pilih/ganti tutor, inbox WA, setujui draf jadwal dari AI |
| Tutor | Lihat jadwal dan client miliknya sendiri, tandai sesi selesai/batal, isi catatan sesi dan absensi grup |

## Katalog produk (data contoh, nama generik)
Setiap paket punya **masa aktif** dan **daftar kuota layanan**. Kuota berkurang saat layanan dipakai.

| Kategori | Varian | Contoh kuota |
|---|---|---|
| Kelas Premium Harian | 1 minggu, 1, 2, 3, 6 bulan | Zoom privat 3x sampai 60x, coaching WA 7 sampai 168 hari, Turnitin, simulasi sidang, workshop publikasi, rekomendasi jurnal, e-book |
| Kelas Recovery Skripsi | 2 minggu, 1 bulan | Zoom 5x/10x (60 menit), audit Bab 1-5, Turnitin, simulasi dosen penguji |
| Bimbingan Privat | Weekend (Sabtu-Minggu) atau weekday (Senin-Jumat) | Per bab, judul, proposal Bab 1-3, Bab 1-5, olah data (SPSS, SmartPLS, NVivo, Python), simulasi sidang, sampai wisuda, artikel/jurnal, PKL, proposal hibah |
| Kelas Online Grup | Bulanan (4-8 pertemuan), Semester (24-32 pertemuan + 2 konsultasi pribadi) | Maksimal 20 peserta per kelas |
| Bimbingan Beasiswa | Bronze, Silver, Gold, harga beda untuk S1 dan S2 | Zoom 2x/3x/6x, review esai, simulasi TPA/TBS, mock interview |

Catatan bisnis:
- Jenjang client: D3, S1, S2, S3. Tutor berjenjang S2 atau S3.
- Client boleh minta ganti tutor, tergantung ketersediaan.
- Estimasi durasi: skripsi 3-6 bulan, tesis 6-12 bulan, disertasi 1-2 tahun.
- Harga promo dan harga coret dicatat (diskon bisa sampai 50%).
- Tidak ada sistem DP atau cicilan. Pembayaran per paket: belum bayar atau lunas, tanggal bayar, bukti transfer.

## Alur utama
1. Client chat ke nomor WA admin.
2. Pesan masuk ke n8n lewat webhook gateway WA, lalu diteruskan ke Laravel.
3. Laravel menyimpan pesan di inbox. AI (Gemini) mengambil data: nama, jenjang, bidang, layanan, tanggal dan jam yang diminta. Hasilnya jadi **draf jadwal**.
4. Admin memeriksa draf, memilih tutor dari daftar saran (bidang, jenjang, jadwal kosong, beban kerja), lalu menyetujui.
5. Jadwal tersimpan di database dan disalin ke Google Sheets.
6. Tutor otomatis mendapat notifikasi WA. Client mendapat konfirmasi WA.
7. Reminder otomatis ke client dan tutor: H-1 dan 1 jam sebelum sesi.
8. Setelah sesi, tutor menandai selesai. Kuota paket berkurang. Pengingat juga dikirim saat masa aktif paket hampir habis dan saat pembayaran belum lunas.

## Fitur MVP
- Client, tutor (bidang, jenjang, jam tersedia), paket dan kuota, pembayaran sederhana.
- Jadwal sesi privat dan kelas grup (kapasitas, absensi per peserta), cek bentrok.
- Saran tutor otomatis.
- Inbox WA di aplikasi (baca dan balas).
- Draf jadwal dari AI, disetujui admin.
- Notifikasi dan reminder lewat n8n.
- Sinkron satu arah ke Google Sheets.
- Dashboard director.

## Data contoh tutor
65 tutor dengan sebaran bidang dan jenjang seperti kondisi nyata. Nama diubah ejaannya, nomor WA fiktif, tanpa kampus, prestasi, atau bio asli. Repo public, jadi tidak ada nama perusahaan.
