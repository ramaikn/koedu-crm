# AI: Chat WhatsApp Menjadi Draf Jadwal

## Alur
```
Chat masuk tersimpan (ReceiveWhatsAppMessage) --> job ExtractScheduleDraftJob (antrean "ai")
  --> ScheduleRequestExtractor (prompt + skema) --> AiProvider --> ScheduleExtraction (divalidasi)
  --> bila permintaan jadwal dan keyakinan >= AI_MIN_CONFIDENCE --> UpsertScheduleDraft (schedule_drafts, status pending)
Inbox: kartu draf --> "Atur jadwal" membuka form jadwal terisi (/schedule/create?draft_id=) --> simpan --> draf ditandai approved
                  --> atau "Tolak" (status rejected)
```
- AI hanya mengusulkan. Jadwal selalu dibuat admin lewat form biasa, jadi semua aturan bentrok dan peringatan tetap berlaku. Draf yang jadwalnya ditolak karena bentrok tetap menunggu.
- Satu percakapan punya satu draf yang menunggu. Chat lanjutan ("jam 2 siang saja") melengkapi draf itu: rincian baru menimpa, yang tidak disebut lagi dipertahankan. Draf yang sudah disetujui atau ditolak tidak ikut diperbarui; permintaan baru membuat draf baru.
- Draf disetujui di dalam transaksi pembuatan jadwal (`ScheduleConsultation`), dengan kunci pada baris draf, jadi dua admin tidak bisa memakai draf yang sama.

## Lapisan AI (`app/Services/Ai`)
| Kelas | Fungsi |
|---|---|
| `AiProvider` | `generateJson(system, prompt, schema)` menghasilkan `AiResult` (data, provider, model) |
| `GeminiProvider` | Google AI Studio. Banyak key: 429 mengistirahatkan key `GEMINI_KEY_COOLDOWN_SECONDS` lalu pindah ke key berikutnya. Key ditolak (400 `API_KEY_INVALID`, 401, 403) istirahat 1 jam. 5xx dan gangguan jaringan pindah key tanpa mengistirahatkan. Permintaan ditolak karena isi/skema (400 lain) tidak dicoba dengan key lain. Status istirahat di cache (dipakai bersama semua worker). Key dikirim lewat header `x-goog-api-key`, tidak di URL atau log |
| `OpenAiCompatibleProvider` | Cadangan berformat OpenAI Chat Completions (OpenAI, OpenRouter, Groq, model lokal). Aktif bila `openai` ada di `AI_PROVIDERS` |
| `FallbackAiProvider` | Mencoba provider sesuai urutan `AI_PROVIDERS`; jawaban tidak sah dari satu provider tetap lanjut ke berikutnya |
| `AiAvailability` | AI aktif hanya bila `AI_ENABLED=true` dan ada key untuk provider terpilih |

Exception: `AiUnavailableException` (limit, jaringan, key), `AiInvalidResponseException` (ditolak atau bukan JSON). Mengganti provider tidak mengubah kode pemakai, cukup `.env`.

## Pembacaan chat (`ScheduleRequestExtractor`)
- Konteks: `AI_CONTEXT_MESSAGES` pesan terakhir (client dan admin diberi label), data client terdaftar bila ada, dan tanggal serta hari ini di zona WITA supaya "Sabtu depan" bisa dihitung.
- Keamanan: isi chat diperlakukan sebagai data, bukan perintah (ditegaskan di instruksi sistem). Output hanya data terstruktur, tidak ada aksi yang bisa dipicu AI. Nomor telepon di dalam chat disamarkan menjadi `[nomor]`, dan setiap pesan dipotong 500 karakter.
- `ScheduleExtraction::fromAi` membuang nilai yang tidak masuk akal: tanggal sudah lewat atau tidak sah, jam di luar 00:00-23:59, durasi di luar 15-480 menit, jenjang atau jenis sesi tidak dikenal. Keyakinan dibatasi 0-1.
- Pengaman biaya: `AI_MAX_CALLS_PER_CONVERSATION_HOUR` panggilan per percakapan per jam. Pesan di bawah 4 karakter dan pesan non-teks dilewati.
- Kegagalan AI: job dicoba ulang sekali (jeda 30 detik), lalu dicatat di log. Chat tetap tersimpan dan admin bisa mengatur jadwal manual.

## Data yang dikirim ke penyedia AI
Teks chat (nomor telepon disamarkan), nama client terdaftar, jenjang, jurusan, dan nama WhatsApp. Tanpa nomor telepon, email, atau data keuangan. Catatan: API gratis dari beberapa penyedia boleh memakai data untuk melatih model. Untuk data client sungguhan, pakai key dengan penagihan aktif atau penyedia yang menjamin data tidak dipakai melatih.

## Menyalakan dan memeriksa
1. Isi `GEMINI_API_KEYS` di `.env` (dipisah koma) dan, bila perlu, `GEMINI_MODEL`.
2. `docker compose restart app worker scheduler`
3. `docker compose exec app php artisan ai:check` memeriksa tiap key (OK, ditolak, kuota habis, model tidak ditemukan) dan menjalankan satu ekstraksi percobaan. API key tidak pernah ditampilkan.
4. Kirim chat permintaan jadwal dari HP lain, lalu lihat kartu "Draf jadwal" di inbox.

Di test, AI dimatikan (`AI_ENABLED=false` di `phpunit.xml`) supaya tidak pernah memanggil jaringan. Test yang butuh AI menyalakannya dan memakai `FakeAiProvider`.
