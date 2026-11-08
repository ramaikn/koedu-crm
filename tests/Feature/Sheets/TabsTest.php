<?php

namespace Tests\Feature\Sheets;

use App\Actions\Enrollments\CreateEnrollment;
use App\Enums\AttendanceStatus;
use App\Enums\BenefitType;
use App\Enums\ConsultationStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Package;
use App\Services\Sheets\SheetTabs;
use App\Services\Sheets\Tabs\ClientTab;
use App\Services\Sheets\Tabs\JadwalTab;
use App\Services\Sheets\Tabs\PaketClientTab;
use Carbon\CarbonImmutable;

class TabsTest extends SheetsTestCase
{
    private function column(array $headers, string $name): int
    {
        $index = array_search($name, $headers, true);
        $this->assertNotFalse($index, "kolom {$name} tidak ada");

        return $index;
    }

    public function test_every_registered_tab_has_matching_header_and_row_widths(): void
    {
        $this->at_('10:00');
        $client = $this->activeClient();
        $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '14:00', [$client]);

        foreach (SheetTabs::keys() as $key) {
            $tab = SheetTabs::make($key);
            foreach ($tab->rows() as $row) {
                $this->assertCount(count($tab->headers()), $row, "tab {$key}");
            }
            $this->assertNotSame('', $tab->title());
        }
        $this->assertSame(['Jadwal', 'Client', 'Paket Client'], array_map(fn ($k) => SheetTabs::make($k)->title(), SheetTabs::keys()));
    }

    // --- Jadwal ---

    public function test_schedule_rows_contain_time_people_status_attendance_and_message_progress(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi Tutor');
        $present = $this->person('Budi Santoso');
        $absent = $this->person('Siti Aminah');
        $session = $this->sessionAt($tutor, self::TUESDAY, '14:00', [$present, $absent], 90, ['status' => ConsultationStatus::Completed, 'title' => 'Olah Data', 'tutor_notified_at' => $this->at('09:00')]);
        $session->participants()->where('client_id', $present->id)->update(['attendance' => AttendanceStatus::Present, 'confirmation_sent_at' => now()]);
        $session->participants()->where('client_id', $absent->id)->update(['attendance' => AttendanceStatus::Absent]);

        $tab = app(JadwalTab::class);
        $row = $tab->rows()[0];
        $h = $tab->headers();

        $this->assertSame($session->id, $row[$this->column($h, 'ID')]);
        $this->assertSame('2026-11-10', $row[$this->column($h, 'Tanggal')]);
        $this->assertSame('Selasa', $row[$this->column($h, 'Hari')]);
        $this->assertSame('14:00', $row[$this->column($h, 'Mulai')]);
        $this->assertSame('15:30', $row[$this->column($h, 'Selesai')]);
        $this->assertSame('Dewi Tutor', $row[$this->column($h, 'Tutor')]);
        $this->assertSame('Budi Santoso, Siti Aminah', $row[$this->column($h, 'Client')]);
        $this->assertSame(2, $row[$this->column($h, 'Peserta')]);
        $this->assertSame('Selesai', $row[$this->column($h, 'Status')]);
        $this->assertSame('1 hadir, 1 tidak hadir', $row[$this->column($h, 'Kehadiran')]);
        $this->assertSame('2026-11-09 09:00', $row[$this->column($h, 'Tutor diberi tahu')]);
        $this->assertSame('1/2', $row[$this->column($h, 'Client dikonfirmasi')]);
        $this->assertSame([$session->id], $tab->syncedConsultationIds());
    }

    public function test_schedule_is_sorted_limited_by_age_and_keeps_deleted_client_names(): void
    {
        $this->at_('10:00');
        $tutor = $this->teacher('Dewi');
        $late = $this->sessionAt($tutor, '2026-11-12', '09:00', [], attributes: ['title' => 'Belakangan']);
        $early = $this->sessionAt($tutor, self::TUESDAY, '09:00', [$gone = $this->person('Budi')], attributes: ['title' => 'Duluan']);
        $this->sessionAt($tutor, '2025-10-01', '09:00', [], attributes: ['title' => 'Terlalu lama']); // lebih dari 12 bulan lalu
        $gone->delete();

        $rows = app(JadwalTab::class)->rows();

        $this->assertSame(['Duluan', 'Belakangan'], array_column($rows, 5));
        $this->assertSame('Budi', $rows[0][8], 'riwayat tetap menampilkan client yang sudah dihapus');
        $this->assertNotContains($late->id + 100, array_column($rows, 0));
        $this->assertSame($early->id, $rows[0][0]);
    }

    public function test_user_text_that_looks_like_a_formula_is_neutralised(): void
    {
        $this->at_('10:00');
        $this->sessionAt($this->teacher('=IMPORTXML("http://jahat")'), self::TUESDAY, '09:00', [$this->person('+cmd|calc')], attributes: ['title' => '=HYPERLINK("http://jahat","klik")']);

        $row = app(JadwalTab::class)->rows()[0];

        $this->assertSame('\'=HYPERLINK("http://jahat","klik")', $row[5]);
        $this->assertSame('\'=IMPORTXML("http://jahat")', $row[7]);
        $this->assertSame("'+cmd|calc", $row[8]);
    }

    // --- Client ---

    public function test_client_rows_keep_phone_as_text_count_sessions_and_leave_out_notes(): void
    {
        $this->at_('10:00');
        $client = $this->activeClient();
        $client->update(['phone' => '6281234567890', 'notes' => 'RAHASIA catatan internal', 'name' => '=cmd', 'university' => 'UNM']);
        $tutor = $this->teacher('Dewi');
        $this->sessionAt($tutor, self::MONDAY, '08:00', [$client], attributes: ['status' => ConsultationStatus::Completed]);
        $this->sessionAt($tutor, self::TUESDAY, '09:00', [$client]);
        $this->sessionAt($tutor, '2026-11-11', '09:00', [$client]);
        Client::factory()->create()->delete(); // client terhapus tidak ikut

        $tab = app(ClientTab::class);
        $rows = $tab->rows();
        $h = $tab->headers();

        $this->assertCount(1, $rows);
        $this->assertNotContains('Catatan', $h);
        $this->assertStringNotContainsString('RAHASIA', json_encode($rows));
        $this->assertSame("'=cmd", $rows[0][$this->column($h, 'Nama')]);
        $this->assertSame("'6281234567890", $rows[0][$this->column($h, 'Nomor WhatsApp')], 'nomor lengkap, tetap berupa teks');
        $this->assertSame(1, $rows[0][$this->column($h, 'Paket aktif')]);
        $this->assertSame(1, $rows[0][$this->column($h, 'Sesi selesai')]);
        $this->assertSame(2, $rows[0][$this->column($h, 'Sesi mendatang')]);
        $this->assertSame('2026-11-09', $rows[0][$this->column($h, 'Terdaftar')]);
    }

    // --- Paket client ---

    public function test_package_rows_show_validity_price_payment_and_quota_progress(): void
    {
        $this->at_('10:00');
        $client = $this->person('Budi Santoso');
        $tutor = $this->teacher('Dewi');
        $package = Package::factory()->create(['name' => 'Premium 1 Bulan', 'duration_days' => 30, 'price' => 1_500_000]);
        $package->benefits()->createMany([
            ['benefit_type' => BenefitType::ZoomSession, 'quantity' => 10],
            ['benefit_type' => BenefitType::EssayReview, 'quantity' => null],
        ]);
        $enrollment = app(CreateEnrollment::class)->handle($client, $package, CarbonImmutable::parse('2026-11-01', 'Asia/Makassar'), $tutor);
        $enrollment->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => $this->at('09:00', '2026-11-02'), 'payment_method' => 'QRIS', 'payment_proof_path' => 'payment-proofs/rahasia.jpg']);
        $enrollment->quotas()->where('benefit_type', BenefitType::ZoomSession)->update(['used' => 3]);
        $client->delete();

        $tab = app(PaketClientTab::class);
        $row = $tab->rows()[0];
        $h = $tab->headers();

        $this->assertSame('Budi Santoso (dihapus)', $row[$this->column($h, 'Client')]);
        $this->assertSame('Premium 1 Bulan', $row[$this->column($h, 'Paket')]);
        $this->assertSame('2026-11-01', $row[$this->column($h, 'Mulai')]);
        $this->assertSame('2026-11-30', $row[$this->column($h, 'Berakhir')]);
        $this->assertSame(21, $row[$this->column($h, 'Sisa hari')]);
        $this->assertSame('Aktif', $row[$this->column($h, 'Status paket')]);
        $this->assertSame(1_500_000, $row[$this->column($h, 'Harga')], 'angka, bukan teks');
        $this->assertSame('Lunas', $row[$this->column($h, 'Status bayar')]);
        $this->assertSame('2026-11-02', $row[$this->column($h, 'Tanggal bayar')]);
        $this->assertSame('QRIS', $row[$this->column($h, 'Metode bayar')]);
        $this->assertSame('Dewi', $row[$this->column($h, 'Tutor pembimbing')]);
        $quota = $row[$this->column($h, 'Kuota terpakai')];
        $this->assertStringContainsString('Sesi Zoom privat 3/10', $quota);
        $this->assertStringContainsString('Review esai 0 (tanpa batas)', $quota);
        $this->assertSame(1, substr_count($quota, ';'), 'dua kuota dipisah titik koma');
        $this->assertStringNotContainsString('rahasia', json_encode($row), 'bukti bayar tidak disalin');
    }

    public function test_remaining_days_are_blank_for_inactive_packages_and_never_negative(): void
    {
        $this->at_('10:00', '2026-12-20');
        $package = Package::factory()->create(['duration_days' => 10]);
        $enrollment = app(CreateEnrollment::class)->handle($this->person('Budi'), $package, CarbonImmutable::parse('2026-12-01', 'Asia/Makassar'));

        $h = app(PaketClientTab::class)->headers();
        $this->assertSame(0, app(PaketClientTab::class)->rows()[0][$this->column($h, 'Sisa hari')], 'paket aktif yang lewat masa tidak negatif');

        $enrollment->update(['status' => 'cancelled']);
        $this->assertSame('', app(PaketClientTab::class)->rows()[0][$this->column($h, 'Sisa hari')]);
    }
}
