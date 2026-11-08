<?php

namespace App\Services\Notifications;

/** Ringkasan satu putaran notifikasi: berapa yang terkirim, ditunda, dilewati, atau gagal, per jenis pesan. */
final class NotificationRunResult
{
    /** @var array<string, array<string, int>> jenis => status => jumlah */
    private array $counts = [];

    /** @var list<array{type: string, recipient: string, phone: string, status: string, body: ?string}> */
    private array $previews = [];

    public function __construct(
        public readonly bool $enabled,
        public readonly bool $windowOpen,
        public readonly bool $dryRun = false,
    ) {}

    public const SENT = 'terkirim';

    public const CLAIMED_ONLY = 'dicatat tanpa pesan';

    public const ALREADY_TAKEN = 'sudah diambil proses lain';

    public const DEFERRED = 'ditunda (di luar jam kirim)';

    public const NOT_ALLOWED = 'dilewati (nomor tidak diizinkan)';

    public const ERROR = 'error';

    public const PLANNED = 'akan dikirim';

    public function add(string $type, string $status): void
    {
        $this->counts[$type][$status] = ($this->counts[$type][$status] ?? 0) + 1;
    }

    public function preview(PlannedNotification $notification, string $status): void
    {
        $this->previews[] = [
            'type' => $notification->type,
            'recipient' => $notification->recipient ?? '-',
            'phone' => $notification->phone ? '...'.substr($notification->phone, -4) : '-',
            'status' => $status,
            'body' => $notification->body,
        ];
    }

    public function total(string $status): int
    {
        return array_sum(array_map(fn (array $byStatus) => $byStatus[$status] ?? 0, $this->counts));
    }

    /** @return list<array{type: string, recipient: string, phone: string, status: string, body: ?string}> */
    public function previews(): array
    {
        return $this->previews;
    }

    /** @return array<string, array<string, int>> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** Bentuk untuk respons API n8n. Isi pesan dan nomor tidak ikut. */
    public function toArray(int $failedRecently): array
    {
        return [
            'enabled' => $this->enabled,
            'window_open' => $this->windowOpen,
            'sent' => $this->total(self::SENT),
            'deferred' => $this->total(self::DEFERRED),
            'not_allowed' => $this->total(self::NOT_ALLOWED),
            'errors' => $this->total(self::ERROR),
            'by_type' => $this->counts,
            'failed_recent' => $failedRecently,
        ];
    }
}
