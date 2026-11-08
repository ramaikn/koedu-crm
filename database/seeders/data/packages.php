<?php

// Katalog paket contoh dengan nama generik. Struktur kuota mengikuti katalog publik bisnis bimbingan.
// Harga paket beasiswa sesuai katalog publik. Harga paket lain hanya PERKIRAAN untuk demo
// (katalog publik tidak mencantumkannya), jadi harus dikonfirmasi tim bisnis sebelum dipakai nyata.
// Kuota null = tanpa batas.

$premium = fn (string $code, string $name, int $days, int $price, array $benefits) => [
    'code' => $code, 'category' => 'premium', 'name' => $name, 'schedule_type' => 'flexible',
    'delivery' => 'private', 'duration_days' => $days, 'price' => $price, 'list_price' => $price * 2,
    'benefits' => $benefits,
];

$privat = fn (string $code, string $name, string $schedule, int $days, int $price, int $zoom, array $extra = []) => [
    'code' => $code, 'category' => 'privat', 'name' => $name, 'schedule_type' => $schedule,
    'delivery' => 'private', 'duration_days' => $days, 'price' => $price, 'list_price' => null,
    'benefits' => ['zoom_session' => $zoom] + $extra,
];

$beasiswa = fn (string $tier, string $level, int $price, int $listPrice, int $days, array $benefits) => [
    'code' => 'BSW-'.strtoupper($tier).'-'.$level, 'category' => 'beasiswa',
    'name' => "Bimbingan Beasiswa {$tier} {$level}", 'schedule_type' => 'flexible', 'delivery' => 'private',
    'degree_level' => $level, 'duration_days' => $days, 'price' => $price, 'list_price' => $listPrice,
    'benefits' => $benefits,
];

$bronze = ['zoom_session' => 2, 'essay_review' => null, 'wa_coaching_days' => 10, 'essay_training' => 1, 'tpa_simulation' => 1, 'mock_interview' => 1];
$silver = ['zoom_session' => 3, 'essay_review' => null, 'wa_coaching_days' => 14, 'essay_training' => 1, 'tpa_practice' => 1, 'tpa_simulation' => 1, 'mock_interview' => 1];
$gold = ['zoom_session' => 6, 'essay_review' => null, 'wa_coaching_days' => 30, 'essay_training' => 2, 'tpa_practice' => 2, 'tpa_simulation' => 1, 'mock_interview' => 2];

return [
    // --- Kelas Premium Harian: Zoom privat + coaching WA + layanan pendukung ---
    $premium('PRM-1W', 'Premium Harian 1 Minggu', 7, 499_000, [
        'zoom_session' => 3, 'wa_coaching_days' => 7, 'judul_recommendation' => 3, 'simulasi_sidang' => 1,
        'journal_recommendation' => 3, 'turnitin' => 2, 'workshop' => 3, 'sinta_guidance' => 1, 'ebook' => 1,
    ]),
    $premium('PRM-1M', 'Premium Harian 1 Bulan', 30, 1_499_000, [
        'zoom_session' => 10, 'wa_coaching_days' => 28, 'judul_recommendation' => 3, 'simulasi_sidang' => 1,
        'journal_recommendation' => 5, 'turnitin' => 1, 'workshop' => 1, 'sinta_guidance' => 1, 'ebook' => 1,
    ]),
    $premium('PRM-2M', 'Premium Harian 2 Bulan', 60, 2_699_000, [
        'zoom_session' => 20, 'wa_coaching_days' => 60, 'judul_recommendation' => 4, 'simulasi_sidang' => 2,
        'journal_recommendation' => 8, 'turnitin' => 2, 'workshop' => 3, 'sinta_guidance' => 1, 'ebook' => 2,
    ]),
    $premium('PRM-3M', 'Premium Harian 3 Bulan', 90, 3_799_000, [
        'zoom_session' => 30, 'wa_coaching_days' => 84, 'judul_recommendation' => 6, 'simulasi_sidang' => 2,
        'journal_recommendation' => 10, 'turnitin' => 3, 'workshop' => 3, 'sinta_guidance' => 2, 'ebook' => 2,
    ]),
    $premium('PRM-6M', 'Premium Harian 6 Bulan', 180, 6_999_000, [
        'zoom_session' => 60, 'wa_coaching_days' => 168, 'judul_recommendation' => 12, 'simulasi_sidang' => 3,
        'journal_recommendation' => 20, 'turnitin' => 6, 'workshop' => 6, 'sinta_guidance' => 3, 'ebook' => 3,
    ]),
    $premium('PRM-SIDANG', 'Simulasi Sidang 1 Minggu', 7, 399_000, [
        'zoom_session' => 3, 'simulasi_sidang' => 3, 'journal_recommendation' => 4, 'wa_coaching_days' => 7,
    ]),

    // --- Kelas Recovery: untuk skripsi bermasalah yang perlu dibedah dan direvisi ---
    [
        'code' => 'REC-2W', 'category' => 'recovery', 'name' => 'Recovery Skripsi 2 Minggu', 'schedule_type' => 'flexible',
        'delivery' => 'private', 'duration_days' => 14, 'price' => 899_000, 'list_price' => 1_799_000,
        'benefits' => ['zoom_session' => 5, 'wa_coaching_days' => 14, 'turnitin' => 1],
    ],
    [
        'code' => 'REC-1M', 'category' => 'recovery', 'name' => 'Recovery Skripsi 1 Bulan', 'schedule_type' => 'flexible',
        'delivery' => 'private', 'duration_days' => 30, 'price' => 1_599_000, 'list_price' => 3_199_000,
        'benefits' => ['zoom_session' => 10, 'wa_coaching_days' => 30, 'turnitin' => 2, 'simulasi_penguji' => 2],
    ],

    // --- Bimbingan Privat: per tahap skripsi, jadwal weekend atau weekday ---
    $privat('PRV-JUDUL-WE', 'Privat Judul (Weekend)', 'weekend', 2, 299_000, 2, ['judul_recommendation' => 3]),
    $privat('PRV-JUDUL-WD', 'Privat Judul (Weekday)', 'weekday', 7, 299_000, 3, ['judul_recommendation' => 3]),
    $privat('PRV-PROPOSAL-WE', 'Privat Proposal Bab 1-3 (Weekend)', 'weekend', 30, 1_299_000, 8),
    $privat('PRV-PROPOSAL-WD', 'Privat Proposal Bab 1-3 (Weekday)', 'weekday', 30, 1_299_000, 12),
    $privat('PRV-BAB15-WD', 'Privat Bab 1-5 (Weekday)', 'weekday', 90, 3_499_000, 30, ['turnitin' => 2, 'simulasi_sidang' => 1]),
    $privat('PRV-BAB45-WD', 'Privat Bab 4-5 (Weekday)', 'weekday', 30, 1_299_000, 10),
    $privat('PRV-OLAHDATA', 'Privat Olah Data (SPSS/SmartPLS/NVivo/Python)', 'flexible', 14, 799_000, 4),
    $privat('PRV-SIDANG', 'Privat Simulasi Sidang', 'flexible', 7, 399_000, 2, ['simulasi_sidang' => 2]),
    $privat('PRV-WISUDA-WD', 'Privat Sampai Wisuda (Weekday)', 'weekday', 180, 6_499_000, 60, ['turnitin' => 4, 'simulasi_sidang' => 2]),
    $privat('PRV-ARTIKEL', 'Privat Artikel Ilmiah/Jurnal', 'flexible', 30, 999_000, 6, ['journal_recommendation' => 5]),
    $privat('PRV-HARIAN', 'Privat Harian (1 Hari Intensif)', 'flexible', 1, 199_000, 1),

    // --- Kelas Online Grup: Zoom, maksimal 20 peserta ---
    [
        'code' => 'GRP-MONTH', 'category' => 'kelas_grup', 'name' => 'Kelas Online Bulanan', 'schedule_type' => 'flexible',
        'delivery' => 'group', 'duration_days' => 30, 'price' => 249_000, 'list_price' => 499_000, 'max_participants' => 20,
        'benefits' => ['group_meeting' => 8],
    ],
    [
        'code' => 'GRP-SEMESTER', 'category' => 'kelas_grup', 'name' => 'Kelas Online Semester', 'schedule_type' => 'flexible',
        'delivery' => 'group', 'duration_days' => 180, 'price' => 1_199_000, 'list_price' => 2_399_000, 'max_participants' => 20,
        'benefits' => ['group_meeting' => 32, 'personal_consultation' => 2],
    ],

    // --- Bimbingan Beasiswa: harga dari katalog publik ---
    $beasiswa('Bronze', 'S1', 399_000, 800_000, 10, $bronze),
    $beasiswa('Silver', 'S1', 599_000, 1_000_000, 14, $silver),
    $beasiswa('Gold', 'S1', 899_000, 1_500_000, 30, $gold),
    $beasiswa('Bronze', 'S2', 699_000, 1_200_000, 10, $bronze),
    $beasiswa('Silver', 'S2', 999_000, 1_500_000, 14, $silver),
    $beasiswa('Gold', 'S2', 1_299_000, 2_000_000, 30, $gold),
];
