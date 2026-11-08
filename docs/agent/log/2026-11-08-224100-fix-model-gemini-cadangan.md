# Uji Gemini sungguhan dan model cadangan

- Waktu: 2026-11-08 22:41 WITA (UTC+8)
- Jenis: fix
- Ringkasan: setelah API key diisi, `ai:check` menemukan model bawaan `gemini-2.5-flash` ditolak Google (404, tidak tersedia untuk akun baru). Provider Gemini sekarang mendukung daftar model cadangan, dan `ai:check` mencoba generate sungguhan. Melengkapi log `2026-11-08-213200-feat-ai-draf-jadwal-dari-chat.md`.

## Temuan
- `GET /models/<model>` mengembalikan 200 untuk model yang sudah pensiun, sehingga pemeriksaan versi pertama menyatakan "OK" padahal generate gagal. Pemeriksaan diganti dengan generate kecil sungguhan dan menampilkan alasan dari Google.
- Model yang sedang sibuk (503) atau timeout membuat satu model saja rapuh. `gemini-flash-latest` sempat timeout, `gemini-3.8-flash` 503.
- Model preview bisa dicabut kapan saja, jadi `GEMINI_MODEL` menerima beberapa model berurutan (sibuk, 404, atau timeout lanjut ke berikutnya dengan key yang sama; 429 pindah key).

## Perubahan
- `GeminiProvider`: parameter `models` (daftar), `probe($index, $model)` mengembalikan status dan pesan Google.
- `AiCheckCommand`: tabel per pasangan key dan model, opsi `--model=a,b` untuk membandingkan model tanpa mengubah `.env`.
- Default model: `gemini-3.1-flash-lite,gemini-flash-latest` (`config/koedu.php`, `.env.example`). `.env` lokal memakai tiga model.

## Verifikasi
- 202 test lulus (5 test baru untuk model cadangan dan probe).
- `ai:check` dengan Gemini sungguhan: ekstraksi contoh benar (jenjang S1, bidang manajemen, olah data SmartPLS, "Sabtu depan" menjadi 2026-11-14, 10:00, 60 menit).
- Webhook simulasi lewat n8n sampai draf: draf pending terbentuk dalam sekitar 9 detik dengan isian benar ("besok jam 3 sore" menjadi 2026-11-09 15:00, 120 menit, S2 hukum). Draf dibuat oleh model kedua karena model pertama sedang gagal, jadi cadangan terbukti bekerja. Data percobaan dihapus.
- Belum: chat sungguhan dari HP, dan ketepatan AI pada ragam bahasa chat mahasiswa dalam jumlah besar.
