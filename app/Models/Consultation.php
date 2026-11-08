<?php

namespace App\Models;

use App\Enums\BenefitType;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationType;
use App\Enums\MeetingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    protected $fillable = [
        'type',
        'tutor_id',
        'title',
        'topic',
        'starts_at',
        'ends_at',
        'meeting_mode',
        'meeting_url',
        'location',
        'capacity',
        'benefit_type',
        'status',
        'tutor_notes',
        'cancel_reason',
        'tutor_notified_at',
        'tutor_notified_starts_at',
        'tutor_notified_tutor_id',
        'tutor_cancel_notified_at',
        'tutor_reminded_at',
        'sheet_synced_at',
        'schedule_draft_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConsultationType::class,
            'status' => ConsultationStatus::class,
            'meeting_mode' => MeetingMode::class,
            'benefit_type' => BenefitType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'tutor_notified_at' => 'datetime',
            'tutor_notified_starts_at' => 'datetime',
            'tutor_cancel_notified_at' => 'datetime',
            'tutor_reminded_at' => 'datetime',
            'sheet_synced_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConsultationParticipant::class);
    }

    public function scheduleDraft(): BelongsTo
    {
        return $this->belongsTo(ScheduleDraft::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sesi aktif (belum batal) yang waktunya beririsan dengan rentang [start, end). */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('status', '!=', ConsultationStatus::Cancelled)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}
