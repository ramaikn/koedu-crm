<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

/**
 * Aturan waktu kirim. Pesan hanya dikirim di jendela (bawaan 07.00-21.00 WITA) supaya tidak mengganggu
 * di malam hari. Pesan yang tertunda dikirim begitu jendela terbuka, kecuali sesinya sudah dekat:
 * perubahan atau pembatalan mendadak tetap dikirim kapan saja.
 */
final class NotificationWindow
{
    public function isOpen(CarbonImmutable $now): bool
    {
        $time = $now->setTimezone(config('app.timezone'))->format('H:i');

        return $time >= config('koedu.notifications.window_start') && $time < config('koedu.notifications.window_end');
    }

    /** @param CarbonImmutable|null $sessionStart waktu sesi terkait; null untuk pesan yang tidak terikat sesi */
    public function allows(CarbonImmutable $now, ?CarbonImmutable $sessionStart): bool
    {
        if ($this->isOpen($now)) {
            return true;
        }

        return $sessionStart !== null && $sessionStart->lessThan($now->addHours((int) config('koedu.notifications.urgent_hours')));
    }

    /** Saat pengingat "besok": hari sebelum sesi pada jam yang ditetapkan. */
    public function dayReminderAt(CarbonImmutable $sessionStart): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', config('koedu.notifications.day_reminder_time')));

        return $sessionStart->setTimezone(config('app.timezone'))->subDay()->setTime($hour, $minute);
    }

    /** Saat pengingat singkat sebelum sesi. */
    public function hourReminderAt(CarbonImmutable $sessionStart): CarbonImmutable
    {
        return $sessionStart->subMinutes((int) config('koedu.notifications.hour_reminder_minutes'));
    }

    /** Pengingat singkat yang sudah terlalu dekat dengan sesi tidak berguna lagi. */
    public function isTooLate(CarbonImmutable $now, CarbonImmutable $sessionStart): bool
    {
        return $sessionStart->lessThan($now->addMinutes((int) config('koedu.notifications.min_lead_minutes')));
    }

    public function digestAt(CarbonImmutable $now): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', config('koedu.notifications.digest_time')));

        return $now->setTimezone(config('app.timezone'))->setTime($hour, $minute);
    }
}
