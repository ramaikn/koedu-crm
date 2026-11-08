# AI membaca chat menjadi draf jadwal

- Waktu: 2026-11-08 21:32 WITA (UTC+8)
- Jenis: feat
- Ringkasan: lapisan AI (Gemini dengan banyak key, cadangan OpenAI-compatible, fallback), pembacaan chat menjadi draf jadwal, kartu draf di inbox, dan form jadwal yang terisi dari draf sudah dibuat. 197 test lulus. Belum diuji dengan API key Gemini sungguhan karena key belum diisi di `.env`.

## Keputusan (dipilih user)
- AI jalan otomatis pada tiap chat masuk dari client, tetapi draf dibuat hanya bila AI menilai itu permintaan jadwal dan cukup yakin (`AI_MIN_CONFIDENCE=0.6`).
- Persetujuan: tombol "Atur jadwal" membuka form jadwal terisi. Admin memilih tutor (ada saran), memeriksa peringatan, lalu menyimpan. Draf otomatis disetujui. AI tidak pernah membuat sesi sendiri.
- Gemini di Laravel (bukan n8n), dengan rotasi key saat limit dan provider lain sebagai cadangan lewat `.env`.

## Hal yang dirancang dengan sengaja
- Isi chat dianggap data dan nomor telepon disamarkan sebelum dikirim ke Google. Hasil AI divalidasi ketat (tanggal sudah lewat, jam atau durasi ekstrem, jenjang tidak dikenal menjadi kosong).
- Pengaman biaya: batas panggilan per percakapan per jam. Kegagalan AI hanya dicatat; chat dan balasan admin tidak terganggu.
- Draf yang jadwalnya ditolak karena bentrok tetap menunggu. Draf yang sudah diproses tidak bisa dipakai dua kali (kunci baris di dalam transaksi).
- Di test AI dimatikan lewat `phpunit.xml`, karena antrean test memakai `sync` dan job akan memanggil jaringan sungguhan.

## Bug yang ditemukan lewat test
- `ScheduleController::create()` bertipe `Response` tetapi bisa mengalihkan saat draf sudah diproses. Diubah menjadi `Response|RedirectResponse`.

## Lokasi
- `app/Services/Ai/*`, `app/Services/Scheduling/{ScheduleRequestExtractor,ScheduleExtraction}.php`
- `app/Actions/Scheduling/UpsertScheduleDraft.php`, `app/Jobs/ExtractScheduleDraftJob.php`, `app/Console/Commands/AiCheckCommand.php`
- `app/Http/Controllers/ScheduleDraftController.php`, perubahan di `ScheduleController`, `InboxController`, `ClientController`, `DashboardController`
- `resources/js/pages/inbox/index.tsx` (kartu draf), `schedule/form.tsx`, `clients/form.tsx`, `dashboard.tsx`
- `config/koedu.php` (bagian `ai`), `.env.example`, `phpunit.xml`
- `tests/Feature/Ai/*`

## Verifikasi
- `php artisan test`: 197 lulus. `tsc --noEmit`, `bun run build`, dan lint lulus.
- Di browser (admin): kartu draf di inbox dengan lencana di daftar, tombol Atur jadwal membuka form terisi (client, tanggal, jam, durasi, topik) beserta saran tutor.
- `ai:check` tanpa key menolak dengan pesan yang jelas.
- Belum diuji: panggilan sungguhan ke Gemini. Bentuk permintaan (skema `responseSchema`, header, nama model `gemini-2.5-flash`) baru diuji dengan respons tiruan, jadi `ai:check` perlu dijalankan setelah key diisi. Ketepatan AI membaca bahasa chat mahasiswa sungguhan juga belum terukur.
- Seeder demo diperbarui ke bentuk draf baru; satu baris draf di database lokal diperbarui manual (tanpa reset).
