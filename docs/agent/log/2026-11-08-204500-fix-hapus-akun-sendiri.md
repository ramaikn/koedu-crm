# Fitur hapus akun sendiri ditutup

- Waktu: 2026-11-08 20:45 WITA (UTC+8)
- Jenis: fix
- Ringkasan: tombol dan route "hapus akun sendiri" dari starter kit dihapus atas keputusan user. Akun hanya dinonaktifkan oleh director, supaya riwayat sesi, chat, dan pembayaran tetap utuh. Melengkapi log `2026-11-08-203000-feat-model-data-role-seeder.md`.

## Keputusan terkait
- Kuota client yang tidak hadir tetap tidak dipotong (`QUOTA_DEDUCT_ON_ABSENT=false`), sudah dikonfirmasi user.

## Lokasi
- `routes/settings.php`, `app/Http/Controllers/Settings/ProfileController.php`, `resources/js/pages/settings/profile.tsx`, `resources/js/components/delete-user.tsx` (dihapus)
- `tests/Feature/Settings/ProfileUpdateTest.php`

## Verifikasi
- `php artisan test`: 40 lulus. `DELETE /settings/profile` membalas 405 dan user tetap ada.
- `tsc --noEmit` dan `bun run build` lulus.
