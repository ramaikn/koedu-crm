<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationParticipant extends Model
{
    protected $fillable = [
        'consultation_id',
        'client_id',
        'enrollment_id',
        'attendance',
        'quota_deducted_at',
        'confirmation_sent_at',
        'confirmed_starts_at',
        'confirmed_tutor_id',
        'cancel_notified_at',
        'reminder_day_sent_at',
        'reminder_hour_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance' => AttendanceStatus::class,
            'quota_deducted_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'confirmed_starts_at' => 'datetime',
            'cancel_notified_at' => 'datetime',
            'reminder_day_sent_at' => 'datetime',
            'reminder_hour_sent_at' => 'datetime',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
