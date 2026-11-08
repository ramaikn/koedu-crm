<?php

namespace App\Models;

use App\Enums\BenefitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageBenefit extends Model
{
    public $timestamps = false;

    protected $fillable = ['package_id', 'benefit_type', 'quantity'];

    protected function casts(): array
    {
        return [
            'benefit_type' => BenefitType::class,
            'quantity' => 'integer',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
