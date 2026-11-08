<?php

namespace App\Enums;

enum MeetingMode: string
{
    use HasOptions;

    case Zoom = 'zoom';
    case GoogleMeet = 'google_meet';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Zoom => 'Zoom',
            self::GoogleMeet => 'Google Meet',
            self::Offline => 'Tatap muka',
        };
    }
}
