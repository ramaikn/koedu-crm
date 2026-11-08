<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

/** Satu kelompok aturan notifikasi. Hanya menentukan apa yang perlu dikirim; tidak mengirim dan tidak mengubah data. */
interface NotificationRule
{
    /** @return list<PlannedNotification> */
    public function plan(CarbonImmutable $now): array;
}
