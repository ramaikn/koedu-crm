<?php

namespace App\Enums;

enum MessageStatus: string
{
    use HasOptions;

    case Received = 'received';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Diterima',
            self::Queued => 'Antre',
            self::Sent => 'Terkirim',
            self::Delivered => 'Sampai',
            self::Read => 'Dibaca',
            self::Failed => 'Gagal',
        };
    }
}
