# WhatsApp: adapter, chat masuk dan keluar, inbox, workflow n8n

- Waktu: 2026-11-08 20:15 WITA (UTC+8)
- Jenis: feat
- Ringkasan: adapter WhatsApp dengan driver WAHA dan Cloud API resmi, penyimpanan chat masuk lewat n8n, pengiriman chat keluar lewat antrean, halaman inbox, dan workflow n8n `wa-incoming` sudah dibuat. 79 test lulus. Uji nyata dengan HP belum dilakukan.

## Keputusan
- Semua aturan ada di Laravel. n8n hanya meneruskan payload mentah dari WAHA, sehingga pindah gateway tidak mengubah workflow.
- Pesan masuk disimpan sekali per `provider_message_id`. Pesan dari diri sendiri, grup, status, dan channel diabaikan.
- ID pengirim `@lid` (privasi WhatsApp) dicari nomornya lewat API WAHA. Pesan yang nomornya tidak ditemukan diabaikan dan dicatat di log.
- Pesan keluar selalu lewat `SendWhatsAppMessage` (antre, 3 kali coba, lalu `failed`). Dipakai juga oleh notifikasi dan reminder nanti.
- Director hanya membaca inbox dan tidak menghapus penanda belum dibaca milik admin.
- Menu sidebar dibatasi per role. Tautan dan nama bawaan starter kit Laravel dihapus, nama aplikasi diambil dari `APP_NAME`.
- Sesi WAHA harus bernama `default` (edisi gratis). Sesi `asya` sebelumnya macet di STARTING, dibuat ulang sebagai `default`.

## Lokasi
- `app/Services/WhatsApp/*`, `app/Actions/WhatsApp/*`, `app/Jobs/SendWhatsAppMessageJob.php`
- `app/Http/Controllers/InboxController.php`, `app/Http/Controllers/Api/*`, `app/Http/Middleware/VerifyN8nToken.php`, `routes/api.php`
- `resources/js/pages/inbox/index.tsx`, `resources/js/components/app-sidebar.tsx`
- `n8n/workflows/wa-incoming.json`, `scripts/n8n-import.sh`
- `tests/Feature/WhatsApp/*`

## Verifikasi
- `php artisan test`: 79 lulus. `tsc --noEmit` dan `bun run build` lulus.
- Uji ujung ke ujung: payload mirip WAHA dikirim ke webhook n8n, tersimpan di database Laravel, pengiriman kedua tidak tersimpan ulang, grup dan pesan sendiri diabaikan.
- Tampilan inbox dicek di browser dengan akun admin contoh: daftar, chat, penanda belum dibaca, form balas.
- Belum diuji: chat nyata dari HP lewat WAHA (bentuk payload `@lid` asli belum dilihat), balasan terkirim ke WhatsApp, dan driver Cloud API terhadap Meta sungguhan (hanya diuji dengan HTTP palsu).
- Batasan: Cloud API belum punya pengiriman template, jadi pesan di luar jendela 24 jam akan ditolak Meta.
