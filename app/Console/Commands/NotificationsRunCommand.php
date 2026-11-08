<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Menjalankan satu putaran notifikasi dan pengingat. Pemicu utamanya workflow n8n (lewat API);
 * perintah ini untuk uji manual dan sebagai pemicu alternatif. Gunakan --dry-run untuk melihat pesan tanpa mengirim.
 */
class NotificationsRunCommand extends Command
{
    protected $signature = 'notifications:run {--dry-run : Tampilkan pesan yang akan dikirim tanpa mengirim atau mencatat apa pun}';

    protected $description = 'Kirim notifikasi dan pengingat WhatsApp yang sudah waktunya';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $dispatcher->run(dryRun: $dryRun);

        if (! $result->enabled && ! $dryRun) {
            $this->warn('Notifikasi mati (NOTIFICATIONS_ENABLED=false). Gunakan --dry-run untuk melihat rencananya.');

            return self::SUCCESS;
        }

        $allowed = config('koedu.notifications.allowed_phones');
        $this->line('Jam kirim: '.($result->windowOpen ? 'terbuka' : 'tertutup (pesan tidak mendesak ditunda)'));
        $this->line('Nomor diizinkan: '.($allowed === [] ? 'semua nomor' : count($allowed).' nomor (mode uji)'));

        if ($dryRun) {
            foreach ($result->previews() as $preview) {
                $this->newLine();
                $this->line("[{$preview['status']}] {$preview['type']} untuk {$preview['recipient']} ({$preview['phone']})");
                $this->line($preview['body'] === null ? '  (tanpa pesan, hanya dicatat)' : preg_replace('/^/m', '  | ', $preview['body']));
            }
        }

        $this->newLine();
        $rows = [];
        foreach ($result->counts() as $type => $byStatus) {
            foreach ($byStatus as $status => $count) {
                $rows[] = [$type, $status, $count];
            }
        }
        $rows === [] ? $this->info('Tidak ada pesan yang perlu diproses.') : $this->table(['Jenis', 'Hasil', 'Jumlah'], $rows);

        return self::SUCCESS;
    }
}
