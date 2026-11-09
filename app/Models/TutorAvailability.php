<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TutorAvailability extends Model
{
    /** Nama hari menurut day_of_week ISO (1 = Senin). */
    public const DAY_NAMES = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public $timestamps = false;

    /** @return list<array{value: int, label: string}> pilihan hari untuk form */
    public static function dayOptions(): array
    {
        return collect(self::DAY_NAMES)->map(fn ($name, $day) => ['value' => $day, 'label' => $name])->values()->all();
    }

    protected $fillable = ['tutor_id', 'day_of_week', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }
}
