<?php

namespace App\Enums;

/**
 * Jenis kuota layanan di dalam paket.
 * Kuota "per sesi" berkurang saat sesi selesai. Kuota "per hari" (coaching WA) hanya
 * menandai masa layanan, jadi tidak dipotong. Kuota lain dipotong manual oleh admin.
 */
enum BenefitType: string
{
    use HasOptions;

    case ZoomSession = 'zoom_session';
    case GroupMeeting = 'group_meeting';
    case PersonalConsultation = 'personal_consultation';
    case SimulasiSidang = 'simulasi_sidang';
    case SimulasiPenguji = 'simulasi_penguji';
    case MockInterview = 'mock_interview';
    case TpaSimulation = 'tpa_simulation';
    case TpaPractice = 'tpa_practice';
    case EssayTraining = 'essay_training';
    case EssayReview = 'essay_review';
    case WaCoachingDays = 'wa_coaching_days';
    case Turnitin = 'turnitin';
    case Workshop = 'workshop';
    case JournalRecommendation = 'journal_recommendation';
    case SintaGuidance = 'sinta_guidance';
    case JudulRecommendation = 'judul_recommendation';
    case Ebook = 'ebook';

    public function label(): string
    {
        return match ($this) {
            self::ZoomSession => 'Sesi Zoom privat',
            self::GroupMeeting => 'Pertemuan kelas grup',
            self::PersonalConsultation => 'Konsultasi pribadi',
            self::SimulasiSidang => 'Simulasi sidang',
            self::SimulasiPenguji => 'Simulasi dosen penguji',
            self::MockInterview => 'Mock interview',
            self::TpaSimulation => 'Simulasi TPA/TBS',
            self::TpaPractice => 'Latihan TPA/TBS',
            self::EssayTraining => 'Pelatihan menulis esai',
            self::EssayReview => 'Review esai',
            self::WaCoachingDays => 'Coaching WhatsApp (hari)',
            self::Turnitin => 'Cek Turnitin',
            self::Workshop => 'Workshop publikasi',
            self::JournalRecommendation => 'Rekomendasi jurnal',
            self::SintaGuidance => 'Pendampingan jurnal SINTA/Scopus',
            self::JudulRecommendation => 'Rekomendasi judul',
            self::Ebook => 'E-book',
        };
    }

    /** Kuota yang dipakai lewat sesi terjadwal dengan tutor. */
    public function isSessionBased(): bool
    {
        return in_array($this, [
            self::ZoomSession,
            self::GroupMeeting,
            self::PersonalConsultation,
            self::SimulasiSidang,
            self::SimulasiPenguji,
            self::MockInterview,
            self::TpaSimulation,
            self::TpaPractice,
            self::EssayTraining,
        ], true);
    }

    /** Kuota berupa jumlah hari layanan, bukan jumlah pemakaian. */
    public function isDayBased(): bool
    {
        return $this === self::WaCoachingDays;
    }

    /** @return list<self> */
    public static function sessionBased(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isSessionBased()));
    }
}
