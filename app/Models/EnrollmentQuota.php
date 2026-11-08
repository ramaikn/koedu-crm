<?php

namespace App\Models;

use App\Enums\BenefitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentQuota extends Model
{
    public $timestamps = false;

    protected $fillable = ['enrollment_id', 'benefit_type', 'total', 'used'];

    protected function casts(): array
    {
        return [
            'benefit_type' => BenefitType::class,
            'total' => 'integer',
            'used' => 'integer',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isUnlimited(): bool
    {
        return $this->total === null;
    }

    /** Sisa kuota. Null berarti tanpa batas. */
    public function remaining(): ?int
    {
        return $this->isUnlimited() ? null : max(0, $this->total - $this->used);
    }

    public function hasRemaining(): bool
    {
        return $this->isUnlimited() || $this->used < $this->total;
    }
}
