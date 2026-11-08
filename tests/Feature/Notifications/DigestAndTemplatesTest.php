<?php

namespace Tests\Feature\Notifications;

use App\Actions\Enrollments\CreateEnrollment;
use App\Enums\BenefitType;
use App\Enums\DraftStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\PaymentStatus;
use App\Enums\MeetingMode;
use App\Models\Client;
use App\Models\Enrollment;
use App\Models\Package;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Notifications\MessageTemplates;
use App\Services\Notifications\NotificationRunResult;
use Carbon\CarbonImmutable;

class DigestAndTemplatesTest extends NotificationTestCase
{
    private const ADMIN_PHONE = '6281100000001';

    private function enrollment(Client $client, int $days, bool $paid): Enrollment
    {
        $package = Package::factory()->create(['name' => 'Premium 1 Bulan', 'duration_days' => $days, 'price' => 1_500_000]);
        $package->benefits()->create(['benefit_type' => BenefitType::ZoomSession, 'quantity' => 5]);
        $enrollment = app(CreateEnrollment::class)->handle($client, $package, CarbonImmutable::now('Asia/Makassar')->subDay()->startOfDay());
        $enrollment->update(['payment_status' => $paid ? PaymentStatus::Paid : PaymentStatus::Unpaid]);

        return $enrollment;
    }

    // --- Ringkasan harian ---

    public function test_digest_reports_every_section_once_per_day(): void
    {
        $this->at_('09:00');
        config(['koedu.notifications.admin_phones' => [self::ADMIN_PHONE]]);

        $this->sessionAt($this->teacher('Dewi Tutor'), self::MONDAY, '13:00', [$this->person('Budi')], attributes: ['title' => 'Olah Data']);
        $this->enrollment($this->person('Siti Aminah'), 4, paid: true); // berakhir dalam 2 hari
        $unpaid = $this->enrollment($this->person('Rani Putri'), 60, paid: false);
        Enrollment::whereKey($unpaid->id)->update(['created_at' => now()->subDays(5)]);
        ScheduleDraft::create(['conversation_id' => WaConversation::create(['phone' => '6282'])->id, 'status' => DraftStatus::Pending, 'extracted' => []]);
        WaMessage::create(['conversation_id' => WaConversation::create(['phone' => '6283'])->id, 'direction' => MessageDirection::Outbound, 'status' => MessageStatus::Failed, 'provider' => 'waha', 'body' => 'x']);

        $this->run_();

        $digest = $this->bodiesTo(self::ADMIN_PHONE)[0];
        $this->assertStringContainsString('Ringkasan harian, Senin, 9 November 2026', $digest);
        $this->assertStringContainsString("Sesi hari ini (1):\n- 13.00 Olah Data (Dewi Tutor)", $digest);
        $this->assertStringContainsString('Paket berakhir dalam 7 hari (1):', $digest);
        $this->assertStringContainsString('- Siti Aminah: Premium 1 Bulan, berakhir', $digest);
        $this->assertStringContainsString('Belum lunas lebih dari 3 hari (1):', $digest);
        $this->assertStringContainsString('- Rani Putri: Premium 1 Bulan, Rp 1.500.000', $digest);
        $this->assertStringContainsString('Draf jadwal menunggu persetujuan: 1', $digest);
        $this->assertStringContainsString('Pesan WhatsApp gagal terkirim (24 jam): 1', $digest);

        $this->at_('15:00');
        $before = $this->outbound()->count();
        $this->run_();
        $this->assertSame($before, $this->outbound()->count(), 'sekali sehari');

        $this->at_('09:00', self::TUESDAY);
        $this->run_();
        $this->assertStringContainsString('Ringkasan harian, Selasa, 10 November 2026', collect($this->bodiesTo(self::ADMIN_PHONE))->last());
    }

    public function test_digest_waits_for_its_time_skips_when_empty_and_goes_to_admin_accounts_too(): void
    {
        config(['koedu.notifications.admin_phones' => [self::ADMIN_PHONE]]);
        User::factory()->admin()->create(['phone' => '081200000002', 'name' => 'Admin Satu']);
        User::factory()->admin()->inactive()->create(['phone' => '081200000003']);
        User::factory()->director()->create(['phone' => '081200000004']);
        $this->sessionAt($this->teacher('Dewi'), self::MONDAY, '13:00', [$this->person('Budi')]);

        $this->at_('07:30');
        $this->assertSame(0, $this->outbound()->filter(fn ($m) => str_contains($m->body, 'Ringkasan'))->count(), 'sebelum jam 08.00 dan di luar jam kirim');

        $this->at_('08:00');
        $this->run_();

        $recipients = $this->outbound()->filter(fn ($m) => str_contains($m->body, 'Ringkasan harian'))->map(fn ($m) => $m->conversation->phone)->sort()->values()->all();
        $this->assertSame([self::ADMIN_PHONE, '6281200000002'], $recipients, 'admin aktif dengan nomor dan nomor dari .env; bukan admin nonaktif atau director');
    }

    public function test_digest_is_not_sent_when_there_is_nothing_to_report(): void
    {
        $this->at_('09:00');
        config(['koedu.notifications.admin_phones' => [self::ADMIN_PHONE]]);

        $this->run_();

        $this->assertNothingSent();
    }

    public function test_long_digest_lists_are_trimmed_but_totals_stay_true(): void
    {
        $this->at_('09:00');
        config(['koedu.notifications.admin_phones' => [self::ADMIN_PHONE]]);
        $tutor = $this->teacher('Dewi');
        foreach (range(1, 12) as $i) {
            $this->sessionAt($tutor, self::MONDAY, '10:00', [], attributes: ['title' => "Sesi {$i}", 'starts_at' => $this->at('00:10')->addMinutes($i * 65), 'ends_at' => $this->at('00:10')->addMinutes($i * 65 + 60)]);
        }

        $this->run_();

        $digest = $this->bodiesTo(self::ADMIN_PHONE)[0];
        $this->assertStringContainsString('Sesi hari ini (12):', $digest);
        $this->assertStringContainsString('dan 4 lainnya (lihat aplikasi)', $digest);
    }

    // --- Template ---

    private function sessionForTemplates(array $attributes = []): \App\Models\Consultation
    {
        $session = $this->sessionAt($this->teacher('Dewi Tutor'), self::TUESDAY, '10:00', [$this->person('Budi')], attributes: $attributes);

        // Dimuat ulang dari database supaya nilai bawaan kolom (mis. meeting_mode) ikut terisi, seperti di aplikasi.
        return $session->fresh(['tutor', 'participants.client']);
    }

    public function test_when_formats_indonesian_dates_with_the_office_timezone_label(): void
    {
        $templates = app(MessageTemplates::class);
        $start = $this->at('10:00', self::TUESDAY);

        $this->assertSame('Selasa, 10 November 2026 pukul 10.00-11.30 WITA', $templates->when($start, $start->addMinutes(90)));
        $this->assertSame('Selasa, 10 November 2026 pukul 10.00 WITA', $templates->when($start));
        $this->assertSame('Selasa, 10 November 2026 pukul 10.00 WITA', $templates->when($start->setTimezone('UTC')), 'selalu dikonversi ke zona waktu kantor');

        config(['app.timezone' => 'Asia/Jakarta']);
        $this->assertStringEndsWith('WIB', $templates->when($start));
    }

    public function test_meeting_details_fall_back_gracefully(): void
    {
        $templates = app(MessageTemplates::class);

        $withoutLink = $this->sessionForTemplates(['meeting_url' => null]);
        $this->assertStringContainsString('Link akan dikirim oleh tim.', $templates->tutorNew($withoutLink));

        $offline = $this->sessionForTemplates(['meeting_mode' => MeetingMode::Offline, 'location' => 'Ruko Piccadilly No. 28']);
        $this->assertStringContainsString('Lokasi: Ruko Piccadilly No. 28', $templates->clientConfirmation($offline, 'Budi'));
        $this->assertStringNotContainsString('Link', $templates->clientConfirmation($offline, 'Budi'));

        $noLocation = $this->sessionForTemplates(['meeting_mode' => MeetingMode::Offline, 'location' => null]);
        $this->assertStringContainsString('Lokasi: akan diinformasikan tim', $templates->clientConfirmation($noLocation, 'Budi'));
    }

    public function test_long_client_lists_are_shortened_for_the_tutor(): void
    {
        $session = $this->sessionAt($this->teacher('Dewi'), self::TUESDAY, '10:00', array_map(fn ($i) => $this->person("Peserta {$i}"), range(1, 8)));

        $message = app(MessageTemplates::class)->tutorNew($session->fresh(['tutor', 'participants.client']));

        $this->assertStringContainsString('Client: Peserta 1, Peserta 2, Peserta 3, Peserta 4, Peserta 5 dan 3 lainnya', $message);
    }

    public function test_messages_are_signed_with_the_configured_sender_and_never_expose_internal_reasons(): void
    {
        config(['koedu.notifications.sender_name' => 'Tim Skripsi Cepat']);
        $session = $this->sessionForTemplates();
        $session->update(['cancel_reason' => 'Rahasia internal']);
        $templates = app(MessageTemplates::class);
        $old = $this->at('09:00', self::TUESDAY);

        foreach ([
            $templates->tutorNew($session), $templates->tutorChanged($session, $old), $templates->tutorRelieved($session, 'Dewi', $old, false),
            $templates->tutorReminder($session, 60), $templates->clientConfirmation($session, 'Budi'), $templates->clientChanged($session, 'Budi', $old),
            $templates->clientTutorChanged($session, 'Budi', 'Lama'), $templates->clientCancelled($session, 'Budi', $old),
            $templates->clientDayReminder($session, 'Budi'), $templates->clientHourReminder($session, 'Budi', 60),
        ] as $message) {
            $this->assertStringContainsString('Tim Skripsi Cepat', $message);
            $this->assertStringNotContainsString('Rahasia internal', $message);
            $this->assertStringNotContainsString("\n\n\n", $message, 'tanpa baris kosong ganda');
        }
    }

    public function test_digest_template_returns_null_when_empty_and_counts_sections(): void
    {
        $empty = ['total' => 0, 'lines' => []];
        $base = ['date' => 'Senin', 'sessions' => $empty, 'expiring' => $empty, 'unpaid' => $empty, 'drafts' => 0, 'unread' => 5, 'failed' => 0];

        $this->assertNull(app(MessageTemplates::class)->adminDigest($base), 'chat belum dibaca saja tidak cukup untuk mengirim ringkasan');

        $text = app(MessageTemplates::class)->adminDigest([...$base, 'drafts' => 2, 'sessions' => ['total' => 9, 'lines' => ['a', 'dan 1 lainnya']]]);
        $this->assertStringContainsString('Sesi hari ini (9):', $text);
        $this->assertStringContainsString('Draf jadwal menunggu persetujuan: 2', $text);
        $this->assertStringContainsString('Chat belum dibaca: 5', $text);
    }
}
