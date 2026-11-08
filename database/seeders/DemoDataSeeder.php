<?php

namespace Database\Seeders;

use App\Actions\Consultations\CompleteConsultation;
use App\Actions\Enrollments\CreateEnrollment;
use App\Enums\AttendanceStatus;
use App\Enums\BenefitType;
use App\Enums\ClientStatus;
use App\Enums\ConsultationType;
use App\Enums\DraftStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\ExpertiseField;
use App\Models\Package;
use App\Models\Tutor;
use App\Models\User;
use App\Models\WaConversation;
use Carbon\CarbonImmutable;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Data demo untuk lokal: client, paket yang dibeli, sesi lampau dan mendatang, kelas grup, chat WA.
 * Faker memakai seed tetap supaya data demo sama setiap kali dibuat ulang.
 */
class DemoDataSeeder extends Seeder
{
    private const CLIENT_COUNT = 45;

    private Generator $faker;

    /** Slot tutor yang sudah terpakai, supaya data demo tidak punya jadwal bentrok. Kunci: tutorId|Y-m-d H:i */
    private array $bookedSlots = [];

    public function __construct(
        private readonly CreateEnrollment $createEnrollment,
        private readonly CompleteConsultation $completeConsultation,
    ) {}

    public function run(): void
    {
        $this->faker = \Faker\Factory::create('id_ID');
        $this->faker->seed(2026);

        $admin = User::where('email', 'admin1@koedu.test')->firstOrFail();
        $tutors = Tutor::with(['expertiseFields', 'availabilities'])->get();
        $fields = ExpertiseField::all();
        $packages = Package::with('benefits')->get()->keyBy('code');
        $today = CarbonImmutable::today();

        $clients = $this->createClients($fields, $admin);

        foreach ($clients as $index => $client) {
            if ($client->status === ClientStatus::Lead) {
                continue; // calon client: belum membeli paket
            }

            $package = $packages[$this->faker->randomElement(['PRM-1M', 'PRM-2M', 'PRM-3M', 'REC-1M', 'PRV-PROPOSAL-WD', 'PRV-BAB15-WD', 'PRV-OLAHDATA', 'BSW-SILVER-S1', 'GRP-MONTH'])];
            $tutor = $this->pickTutor($tutors, $client);
            $startsOn = $today->subDays($this->faker->numberBetween(5, 40));

            $enrollment = $this->createEnrollment->handle($client, $package, $startsOn, $tutor, $admin);
            $this->recordPayment($enrollment, $admin, paid: $index % 6 !== 0);

            if ($package->delivery->value === 'private') {
                $this->createPrivateSessions($enrollment, $tutor, $admin, $today);
            }
        }

        $this->createGroupClass($packages['GRP-MONTH'], $tutors, $admin, $today);
        $this->createWhatsappConversations($clients);
    }

    /** @return Collection<int, Client> */
    private function createClients(Collection $fields, User $admin): Collection
    {
        $universities = ['Universitas Negeri Makassar', 'Universitas Hasanuddin', 'UIN Alauddin Makassar', 'Universitas Muslim Indonesia', 'Universitas Muhammadiyah Makassar', 'Universitas Bosowa', 'Universitas Gadjah Mada', 'Universitas Brawijaya'];

        return collect(range(1, self::CLIENT_COUNT))->map(function (int $i) use ($fields, $admin, $universities) {
            $field = $fields->random();
            $level = $this->faker->randomElement(['S1', 'S1', 'S1', 'S1', 'S2', 'S2', 'S3', 'D3']);

            return Client::updateOrCreate(
                ['phone' => '628001'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)],
                [
                    'name' => $this->faker->name(),
                    'email' => "client{$i}@contoh.test",
                    'university' => $this->faker->randomElement($universities),
                    'study_program' => $field->name,
                    'expertise_field_id' => $field->id,
                    'degree_level' => $level,
                    'status' => $i <= 6 ? ClientStatus::Lead : ClientStatus::Active,
                    'source' => $this->faker->randomElement(['whatsapp', 'whatsapp', 'website', 'instagram', 'referral']),
                    'created_by' => $admin->id,
                ],
            );
        });
    }

    /** Tutor dengan bidang yang sama lebih dulu, lalu rumpun yang sama, lalu acak. */
    private function pickTutor(Collection $tutors, Client $client): Tutor
    {
        $sameField = $tutors->filter(fn (Tutor $t) => $t->expertiseFields->contains('id', $client->expertise_field_id));
        if ($sameField->isNotEmpty()) {
            return $sameField->random();
        }

        $group = ExpertiseField::find($client->expertise_field_id)?->group;
        $sameGroup = $tutors->filter(fn (Tutor $t) => $t->expertiseFields->contains('group', $group));

        return $sameGroup->isNotEmpty() ? $sameGroup->random() : $tutors->random();
    }

    private function recordPayment(Enrollment $enrollment, User $admin, bool $paid): void
    {
        if (! $paid) {
            return;
        }

        $enrollment->update([
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => $enrollment->starts_on->copy()->setTime(10, 0),
            'payment_method' => $this->faker->randomElement(['Transfer BCA', 'Transfer BRI', 'Transfer Mandiri', 'QRIS']),
            'payment_recorded_by' => $admin->id,
        ]);
    }

    /** 1-4 sesi lampau (diselesaikan, kuota terpotong) dan 1-2 sesi mendatang. */
    private function createPrivateSessions(Enrollment $enrollment, Tutor $tutor, User $admin, CarbonImmutable $today): void
    {
        $quota = $enrollment->quotaFor(BenefitType::ZoomSession);
        if ($quota === null) {
            return;
        }

        $past = min($this->faker->numberBetween(1, 4), $quota->total ?? 4);
        $upcoming = $this->faker->numberBetween(1, 2);

        $cursor = $enrollment->starts_on->toImmutable();
        for ($i = 0; $i < $past + $upcoming; $i++) {
            $isPast = $i < $past;
            $from = $isPast ? $cursor : max($cursor, $today->addDay());
            $slot = $this->nextFreeSlot($tutor, $from, $isPast ? $today->subDay() : $today->addDays(21));
            if ($slot === null) {
                break;
            }
            $cursor = $slot->addDay();

            $consultation = $this->createConsultation($tutor, $admin, $slot, ConsultationType::Private, 1, BenefitType::ZoomSession, 'Sesi '.$enrollment->package->name);
            $consultation->participants()->create(['client_id' => $enrollment->client_id, 'enrollment_id' => $enrollment->id]);

            if ($isPast) {
                // Sebagian kecil tidak hadir, supaya laporan kehadiran punya variasi.
                $participant = $consultation->participants()->first();
                $status = $this->faker->boolean(10) ? AttendanceStatus::Absent : AttendanceStatus::Present;
                $this->completeConsultation->handle($consultation, [$participant->id => $status], 'Progres sesuai target, lanjut revisi sesuai catatan.');
            }
        }
    }

    private function createGroupClass(Package $package, Collection $tutors, User $admin, CarbonImmutable $today): void
    {
        $members = Enrollment::where('package_id', $package->id)->get();
        $tutor = $tutors->firstWhere('name', 'Roby Agape Barus') ?? $tutors->first();

        foreach ([-7, -3, 2, 6] as $offset) {
            $slot = $this->nextFreeSlot($tutor, $today->addDays($offset), $today->addDays($offset + 6));
            if ($slot === null) {
                continue;
            }

            $consultation = $this->createConsultation($tutor, $admin, $slot, ConsultationType::Group, $package->max_participants ?? 20, BenefitType::GroupMeeting, 'Kelas Online: Metodologi Penelitian');
            foreach ($members as $member) {
                $consultation->participants()->create(['client_id' => $member->client_id, 'enrollment_id' => $member->id]);
            }

            if ($slot->isPast()) {
                $this->completeConsultation->handle($consultation, [], 'Materi: menyusun kerangka teori dan hipotesis.');
            }
        }
    }

    private function createConsultation(Tutor $tutor, User $admin, CarbonImmutable $start, ConsultationType $type, int $capacity, BenefitType $benefit, string $title): Consultation
    {
        return Consultation::create([
            'type' => $type,
            'tutor_id' => $tutor->id,
            'title' => $title,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(60),
            'meeting_mode' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/'.$this->faker->numerify('9#########'),
            'capacity' => $capacity,
            'benefit_type' => $benefit,
            'created_by' => $admin->id,
            // Sesi lampau dianggap notifikasinya sudah terkirim.
            'tutor_notified_at' => $start->isPast() ? $start->subDays(2) : null,
        ]);
    }

    /** Cari slot 60 menit pertama dalam jam tersedia tutor, antara dua tanggal, yang belum terpakai. */
    private function nextFreeSlot(Tutor $tutor, CarbonImmutable $from, CarbonImmutable $until): ?CarbonImmutable
    {
        for ($day = $from->startOfDay(); $day->lte($until); $day = $day->addDay()) {
            foreach ($tutor->availabilities->where('day_of_week', $day->dayOfWeekIso) as $availability) {
                $start = $day->setTimeFromTimeString($availability->start_time);
                $key = $tutor->id.'|'.$start->format('Y-m-d H:i');

                if (! isset($this->bookedSlots[$key])) {
                    $this->bookedSlots[$key] = true;

                    return $start;
                }
            }
        }

        return null;
    }

    /** Chat WA contoh dari calon client, salah satunya sudah punya draf jadwal dari AI. */
    private function createWhatsappConversations(Collection $clients): void
    {
        $chats = [
            ['Kak, saya mau tanya paket bimbingan skripsi. Saya S1 manajemen, baru mau mulai bab 1.', 'Halo Kak, bisa. Untuk mulai dari bab 1 kami sarankan Premium Harian 1 Bulan atau Privat Proposal. Kakak lebih suka jadwal weekday atau weekend?'],
            ['Saya mau daftar privat olah data SmartPLS. Bisa Sabtu depan jam 10 pagi?', null],
            ['Kak, jadwal bimbingan saya besok jam berapa ya?', 'Besok jam 19.00 WITA via Zoom ya Kak, link dikirim 1 jam sebelum sesi.'],
        ];

        foreach ($chats as $i => [$incoming, $reply]) {
            $client = $clients[$i];
            $at = CarbonImmutable::now()->subHours(6 - $i);

            $conversation = WaConversation::updateOrCreate(
                ['phone' => $client->phone],
                ['display_name' => $client->name, 'client_id' => $client->id, 'last_message_at' => $at, 'unread_count' => $reply ? 0 : 1],
            );
            $conversation->messages()->delete();

            $inbound = $conversation->messages()->create([
                'direction' => MessageDirection::Inbound, 'body' => $incoming, 'status' => MessageStatus::Received,
                'provider' => 'waha', 'provider_message_id' => "demo-in-{$i}", 'sent_at' => $at,
            ]);

            if ($reply) {
                $conversation->messages()->create([
                    'direction' => MessageDirection::Outbound, 'body' => $reply, 'status' => MessageStatus::Read,
                    'provider' => 'waha', 'provider_message_id' => "demo-out-{$i}", 'sent_at' => $at->addMinutes(4),
                ]);

                continue;
            }

            // Chat permintaan jadwal: AI sudah membuat draf, menunggu persetujuan admin.
            $nextSaturday = CarbonImmutable::now()->next(CarbonImmutable::SATURDAY)->setTime(10, 0);
            $conversation->scheduleDrafts()->delete();
            $conversation->scheduleDrafts()->create([
                'source_message_id' => $inbound->id,
                'client_id' => $client->id,
                'status' => DraftStatus::Pending,
                'ai_provider' => 'gemini',
                'ai_model' => 'demo',
                // Bentuk yang sama dengan hasil UpsertScheduleDraft dari pembacaan AI.
                'extracted' => [
                    'client_name' => $client->name,
                    'degree_level' => $client->degree_level?->value,
                    'field_of_study' => 'Manajemen',
                    'service_note' => 'Olah data SmartPLS',
                    'requested_date' => $nextSaturday->toDateString(),
                    'requested_time' => '10:00',
                    'duration_minutes' => 60,
                    'session_type' => 'private',
                    'summary' => 'Client minta sesi olah data SmartPLS hari Sabtu jam 10 pagi.',
                    'confidence' => 0.86,
                    'source_message_ids' => [$inbound->id],
                ],
            ]);
        }
    }
}
