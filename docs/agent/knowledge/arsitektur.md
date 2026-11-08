# Arsitektur

## Stack
| Bagian | Pilihan | Alasan |
|---|---|---|
| Backend | Laravel 12 (PHP 8.4) | Login, role, queue, scheduler sudah ada. Cocok untuk CRM internal |
| Frontend | React + Inertia.js + Vite (bun) | Satu codebase dengan Laravel, tanpa API terpisah untuk UI |
| Database | PostgreSQL 17 | Sumber data utama |
| Queue/cache | Redis | Antrean kirim WA, sinkron Sheets, panggilan AI |
| Otomasi | n8n (self-hosted) | Alur webhook WA, notifikasi, reminder |
| WhatsApp | WAHA (simulasi) lewat adapter | Bisa diganti ke WhatsApp Cloud API resmi lewat `.env` |
| AI | Google AI Studio (Gemini) lewat adapter | Rotasi banyak API key, fallback provider |
| Sheets | Google Sheets API (service account) | Cermin satu arah dari database |
| Jalankan | Docker Compose | Sama di lokal (Docker Desktop) dan VPS Ubuntu |

## Gambaran
```
Client WA <-> WAHA --webhook--> n8n --> Laravel API (/api/webhooks/whatsapp)
                                          |
Staf --> Web React (Inertia) --> Laravel --+--> PostgreSQL (sumber utama)
                                          +--> Redis queue --> worker
                                          +--> Google Sheets (cermin)
                                          +--> AI (Gemini, rotasi key)
n8n cron/webhook <--> Laravel API: kirim notif tutor, reminder, tagihan
```

## Adapter (mudah diganti)
- `WhatsAppProvider`: `sendText`, `parseWebhook`. Driver `waha` dan `cloud_api`. Dipilih lewat `WHATSAPP_DRIVER`.
- `AiProvider`: `extractJson(prompt, schema)`. Driver `gemini`, provider lain bisa ditambah. `AI_PROVIDERS` menentukan urutan fallback. `GEMINI_API_KEYS` berisi banyak key dipisah koma. Key yang kena limit (HTTP 429) diistirahatkan sementara, lalu key berikutnya dipakai.
- `SheetSync`: menyalin jadwal ke Sheets lewat queue. Kalau gagal, dicoba ulang tanpa menghentikan aplikasi.

## Pembagian tugas Laravel dan n8n
- Laravel memegang data, aturan bisnis, AI, dan pengiriman WA (satu pintu, ada log).
- n8n mengatur alur: menerima webhook WA, memanggil endpoint Laravel, menjadwalkan reminder, dan alur tambahan yang bisa diubah tim tanpa coding.
- n8n memanggil Laravel dengan token API khusus (header), bukan akun pengguna.

## Lingkungan
- Lokal: Windows + WSL2 + Docker Desktop.
- Produksi: VPS Ubuntu terpisah (belum ditentukan). Port n8n dan WAHA hanya untuk localhost, akses lewat SSH tunnel atau reverse proxy dengan auth.
