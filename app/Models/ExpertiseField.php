<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ExpertiseField extends Model
{
    protected $fillable = ['name', 'group'];

    public function tutors(): BelongsToMany
    {
        return $this->belongsToMany(Tutor::class);
    }
}
