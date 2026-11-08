<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\DegreeLevel;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'university',
        'study_program',
        'expertise_field_id',
        'degree_level',
        'status',
        'source',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'degree_level' => DegreeLevel::class,
            'status' => ClientStatus::class,
        ];
    }

    /** Nomor selalu disimpan dalam format 62xxx supaya cocok dengan nomor dari webhook WA. */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : PhoneNumber::normalize($value));
    }

    public function expertiseField(): BelongsTo
    {
        return $this->belongsTo(ExpertiseField::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ConsultationParticipant::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(WaConversation::class);
    }

    /** Client yang pernah atau sedang dibimbing oleh tutor tertentu. */
    public function scopeHandledByTutor(Builder $query, Tutor $tutor): Builder
    {
        return $query->where(function (Builder $q) use ($tutor) {
            $q->whereHas('enrollments', fn (Builder $e) => $e->where('primary_tutor_id', $tutor->id))
                ->orWhereHas('participations.consultation', fn (Builder $c) => $c->where('tutor_id', $tutor->id));
        });
    }
}
