<?php

namespace App\Models;

use App\Enums\DegreeLevel;
use App\Enums\DeliveryMode;
use App\Enums\PackageCategory;
use App\Enums\ScheduleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    /** @use HasFactory<\Database\Factories\PackageFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'category',
        'name',
        'schedule_type',
        'delivery',
        'degree_level',
        'duration_days',
        'price',
        'list_price',
        'max_participants',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => PackageCategory::class,
            'schedule_type' => ScheduleType::class,
            'delivery' => DeliveryMode::class,
            'degree_level' => DegreeLevel::class,
            'price' => 'integer',
            'list_price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function benefits(): HasMany
    {
        return $this->hasMany(PackageBenefit::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
