<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaConversation extends Model
{
    protected $fillable = ['phone', 'display_name', 'client_id', 'last_message_at', 'unread_count'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class, 'conversation_id');
    }

    public function scheduleDrafts(): HasMany
    {
        return $this->hasMany(ScheduleDraft::class, 'conversation_id');
    }
}
