# WhatsApp dan n8n

## Alur pesan
```
Chat masuk:  Client --> WAHA --webhook--> n8n (wa-incoming) --token--> Laravel /api/n8n/whatsapp/incoming
                                                                        |--> parseWebhook (driver) --> ReceiveWhatsAppMessage --> wa_messages
Chat keluar: Admin (inbox) / sistem --> SendWhatsAppMessage --> wa_messages (antre) --> job SendWhatsAppMessageJob --> driver --> WAHA/Meta
```
- WAHA mengirim ke n8n lewat `WHATSAPP_HOOK_URL` di `compose.yaml`. n8n hanya meneruskan body mentah, semua aturan ada di Laravel.
- n8n memanggil Laravel dengan header `X-Koedu-Token` (= `N8N_API_TOKEN`). Token kosong berarti endpoint ditolak.

## Adapter (`app/Services/WhatsApp`)
| Kelas | Fungsi |
|---|---|
| `WhatsAppProvider` | Antarmuka: `name()`, `sendText()`, `parseWebhook()` |
| `WahaDriver` | Simulasi. Kirim lewat `/api/sendText`. Abaikan pesan dari diri sendiri, grup, status, channel. ID `@lid` dicari nomornya lewat `/api/{session}/lids/{lid}` |
| `CloudApiDriver` | WhatsApp resmi (Meta). Kirim lewat Graph API. Punya cek tanda tangan HMAC dan verifikasi webhook |
| `IncomingMessage` | Bentuk pesan masuk yang sama untuk semua driver |

Driver dipilih lewat `WHATSAPP_DRIVER` (`waha` atau `cloud_api`), dibind di `AppServiceProvider`.

### Pindah ke WhatsApp resmi
1. Isi `WA_CLOUD_*` di `.env`, ubah `WHATSAPP_DRIVER=cloud_api`, restart `app worker`.
2. Daftarkan webhook Meta ke `https://<domain>/api/webhooks/whatsapp` (verify token = `WA_CLOUD_VERIFY_TOKEN`). Controller `CloudWebhookController` aktif hanya untuk driver ini.
3. Batasan Meta: balasan bebas hanya boleh dalam 24 jam sejak chat terakhir client. Di luar itu wajib template yang disetujui Meta. Pengiriman template belum dibuat, perlu ditambah sebelum reminder ke client dikirim lewat WA resmi.

## Penyimpanan pesan
- `ReceiveWhatsAppMessage`: pesan dengan `provider_message_id` yang sama hanya disimpan sekali (cek awal + indeks unik). Percakapan dibuat per nomor, otomatis terhubung ke client bila nomornya cocok, dan menambah `unread_count`.
- `SendWhatsAppMessage`: satu pintu untuk semua pesan keluar. Pesan dicatat status `queued`, dikirim worker di antrean `whatsapp`. Gagal dicoba ulang 3 kali (jeda 10 dan 60 detik), lalu `failed` dengan alasan tersimpan.

## Inbox di aplikasi
- Route: `/inbox`, `/inbox/{id}`, `POST /inbox/{id}/messages`. Hanya admin dan director. Admin membaca dan membalas, director hanya membaca dan tidak menghapus penanda belum dibaca.
- Halaman `resources/js/pages/inbox/index.tsx` memuat ulang daftar dan chat tiap 5 detik selama tab terlihat.

## n8n
- Workflow disimpan di `n8n/workflows/*.json` (id tetap, ditulis di dalam file). Impor dan aktifkan: `sh scripts/n8n-import.sh`.
- Variabel yang dipakai workflow: `KOEDU_API_URL`, `KOEDU_N8N_TOKEN` (diset di `compose.yaml`).
- Workflow yang ada: `wa-incoming` (WAHA ke Laravel, retry 3 kali) `notifikasi-berkala` (pemicu tiap 5 menit untuk notifikasi dan pengingat, lihat [notifikasi.md](notifikasi.md)), dan `sheets-rekonsiliasi` (pencocokan penuh Google Sheets tiap malam 02.00, lihat [google-sheets.md](google-sheets.md)).

## WAHA
- Edisi gratis (CORE): satu sesi bernama `default`. Sesi ditautkan dengan scan QR di http://localhost:3000/dashboard.
- Dashboard WAHA perlu `WAHA_API_KEY` diisi pada koneksi server (ikon kunci).
- WAHA memakai jalur tidak resmi, nomor berisiko diblokir WhatsApp. Pakai nomor terpisah untuk simulasi.
