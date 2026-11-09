<?php

namespace App\Models;

use App\Enums\DegreeLevel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tutor extends Model
{
    /** @use HasFactory<\Database\Factories\TutorFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'highest_degree',
        'max_weekly_sessions',
        'is_active',
        'bio',
    ];

    protected function casts(): array
    {
        return [
            'highest_degree' => DegreeLevel::class,
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expertiseFields(): BelongsToMany
    {
        return $this->belongsToMany(ExpertiseField::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(TutorAvailability::class);
    }

    /**
     * Ganti seluruh jam tersedia dengan isi form (data sudah divalidasi). Panggil di dalam transaksi.
     *
     * @param  list<array{day_of_week: int, start_time: string, end_time: string}>  $slots
     */
    public function replaceAvailabilities(array $slots): void
    {
        $this->availabilities()->delete();
        $this->availabilities()->createMany(collect($slots)->map(fn ($s) => [
            'day_of_week' => $s['day_of_week'],
            'start_time' => $s['start_time'],
            'end_time' => $s['end_time'],
        ])->all());
        $this->unsetRelation('availabilities');
    }

    /** @return list<array{day_of_week: int, start_time: string, end_time: string}> jam tersedia untuk isian form */
    public function availabilitiesForForm(): array
    {
        return $this->availabilities->sortBy(['day_of_week', 'start_time'])->map(fn (TutorAvailability $s) => [
            'day_of_week' => $s->day_of_week,
            'start_time' => substr($s->start_time, 0, 5),
            'end_time' => substr($s->end_time, 0, 5),
        ])->values()->all();
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    /** Paket client yang dipegang tutor ini sebagai pembimbing utama. */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'primary_tutor_id');
    }

    /**
     * Apakah rentang waktu ini seluruhnya berada di dalam satu jam tersedia tutor pada hari itu.
     * Relasi availabilities harus sudah dimuat. Jam dibandingkan dalam format H:i supaya tidak
     * bergantung pada ada tidaknya detik di nilai kolom time.
     */
    public function isAvailableBetween(CarbonInterface $start, CarbonInterface $end): bool
    {
        if (! $start->isSameDay($end)) {
            return false;
        }

        $from = $start->format('H:i');
        $to = $end->format('H:i');

        return $this->availabilities
            ->where('day_of_week', $start->dayOfWeekIso)
            ->contains(fn (TutorAvailability $slot) => substr($slot->start_time, 0, 5) <= $from && substr($slot->end_time, 0, 5) >= $to);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
