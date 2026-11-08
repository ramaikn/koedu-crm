# Model Data dan Hak Akses

## Tabel utama
| Tabel | Isi | Catatan |
|---|---|---|
| `users` | Akun login + `role` (director/admin/tutor), `is_active` | Akun nonaktif ditolak saat login. Tidak ada registrasi publik |
| `tutors` | Profil tutor, jenjang S2/S3, `max_weekly_sessions` | Satu tutor = satu user role tutor |
| `expertise_fields` + `expertise_field_tutor` | Bidang keahlian per rumpun (`group`) | Saran tutor: bidang sama > rumpun sama |
| `tutor_availabilities` | Jam tersedia rutin per hari (ISO 1 = Senin) | |
| `clients` | Mahasiswa, nomor WA format `62xxx`, jenjang, bidang, status | Soft delete. Nomor dinormalisasi otomatis (`App\Support\PhoneNumber`) |
| `packages` + `package_benefits` | Katalog paket + kuota per jenis layanan | `quantity` null = tanpa batas |
| `enrollments` + `enrollment_quotas` | Paket yang dibeli client, status bayar, salinan kuota (`total`, `used`) | Harga dan kuota disalin saat beli (`CreateEnrollment`) |
| `consultations` | Sesi privat/grup: tutor, waktu, link meeting, `benefit_type` yang dipotong | Penanda notifikasi: `tutor_notified_at`, `tutor_reminded_at`, `sheet_synced_at` |
| `consultation_participants` | Peserta sesi + absensi + penanda reminder per client | Privat = 1 peserta, grup = sampai `capacity` |
| `wa_conversations` + `wa_messages` | Inbox WA, `provider_message_id` unik (anti dobel webhook) | |
| `schedule_drafts` | Hasil ekstraksi AI dari chat, menunggu persetujuan admin | |

Enum ada di `app/Enums` (nilai disimpan sebagai string). `BenefitType` membedakan kuota per sesi, per hari (coaching WA), dan manual.

## Aturan bisnis yang sudah ada di kode
- `CreateEnrollment`: masa aktif inklusif (paket 30 hari mulai 1 Nov berakhir 30 Nov).
- `CompleteConsultation`: absensi + potong kuota `benefit_type` peserta. Tidak memotong dua kali (`quota_deducted_at`) dan tidak melebihi total. Client yang tidak hadir tidak dipotong, kecuali `QUOTA_DEDUCT_ON_ABSENT=true`.
- Zona waktu aplikasi dan sesi Postgres sama-sama `APP_TIMEZONE` (Asia/Makassar).

## Hak akses (Policy di `app/Policies`, middleware `role:...`)
| Data | Director | Admin | Tutor |
|---|---|---|---|
| Akun pengguna | Kelola (tidak bisa menonaktifkan diri sendiri) | - | - |
| Katalog paket | Kelola | Lihat | - |
| Client | Lihat | Kelola | Lihat client yang ia bimbing |
| Paket client dan pembayaran | Lihat | Kelola | Lihat milik bimbingannya |
| Sesi | Lihat | Kelola | Lihat dan selesaikan sesinya sendiri |
| Profil tutor | Kelola | Kelola | Ubah jam tersedia sendiri |
| Inbox WA | Lihat | Baca dan balas | - |
| Draf jadwal AI | Lihat | Setujui/tolak | - |

## Data contoh (seeder)
- Selalu dijalankan: bidang keahlian, katalog paket (`database/seeders/data/`). Harga non-beasiswa masih perkiraan.
- Hanya lokal: akun `director@koedu.test`, `admin1@koedu.test`, `admin2@koedu.test`, dan 65 tutor `<nama>.<nomor>@koedu.test`. Password dari `SEED_USER_PASSWORD`. Ditambah 45 client, paket, sesi lampau/mendatang, kelas grup, dan contoh chat WA.
