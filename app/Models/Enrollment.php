<?php

namespace App\Models;

use App\Enums\BenefitType;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    protected $fillable = [
        'client_id',
        'package_id',
        'primary_tutor_id',
        'status',
        'starts_on',
        'ends_on',
        'price',
        'payment_status',
        'paid_at',
        'payment_method',
        'payment_proof_path',
        'payment_recorded_by',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'payment_status' => PaymentStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'paid_at' => 'datetime',
            'price' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function primaryTutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class, 'primary_tutor_id');
    }

    public function quotas(): HasMany
    {
        return $this->hasMany(EnrollmentQuota::class);
    }

    public function quotaFor(BenefitType $type): ?EnrollmentQuota
    {
        return $this->quotas->firstWhere('benefit_type', $type);
    }

    /** Paket yang masih bisa dipakai pada tanggal tertentu (default: hari ini). */
    public function scopeUsableOn(Builder $query, ?\DateTimeInterface $date = null): Builder
    {
        $day = ($date ?? now())->format('Y-m-d');

        return $query->where('status', EnrollmentStatus::Active)
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day);
    }
}
