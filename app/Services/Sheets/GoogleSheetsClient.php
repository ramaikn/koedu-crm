<?php

namespace App\Services\Sheets;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien tipis Google Sheets API v4 dengan akun layanan. Hanya yang dibutuhkan: memastikan tab ada
 * dan menulis ulang isi satu tab. Memakai HTTP client Laravel, tanpa pustaka Google yang berat.
 */
final class GoogleSheetsClient
{
    private const API = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private const TOKEN_CACHE_KEY = 'sheets:access-token';

    /** Baris data per permintaan tulis, supaya satu permintaan tidak terlalu besar. */
    private const CHUNK_ROWS = 2000;

    /** Kolom terjauh yang dihapus saat membersihkan isi tab (ZZ = 702 kolom, jauh melebihi yang dipakai). */
    private const LAST_COLUMN = 'ZZ';

    /** Baris 1 catatan, baris 2 judul kolom, data mulai baris 3. */
    public const DATA_START_ROW = 3;

    public function __construct(
        private readonly string $spreadsheetId,
        private readonly ServiceAccountCredentials $credentials,
        private readonly Cache $cache,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function url(): string
    {
        return "https://docs.google.com/spreadsheets/d/{$this->spreadsheetId}";
    }

    public function serviceAccountEmail(): string
    {
        return $this->credentials->clientEmail();
    }

    /**
     * Judul spreadsheet dan daftar tab. Sekaligus menguji akses: gagal bila Sheet belum dibagikan.
     *
     * @return array{title: string, tabs: array<string, int>} tab => sheetId
     */
    public function inspect(): array
    {
        $json = $this->send(fn (PendingRequest $r) => $r->get($this->base(), [
            'fields' => 'properties.title,sheets.properties(sheetId,title)',
        ]))->json();

        $tabs = [];
        foreach ($json['sheets'] ?? [] as $sheet) {
            $tabs[$sheet['properties']['title']] = $sheet['properties']['sheetId'];
        }

        return ['title' => $json['properties']['title'] ?? '', 'tabs' => $tabs];
    }

    /**
     * Menulis ulang satu tab: catatan di baris 1, judul kolom di baris 2, lalu semua data. Isi lama dihapus lebih dulu,
     * sehingga baris yang sudah tidak ada di database ikut hilang. Tab dibuat (dan diformat) bila belum ada.
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows  sel sudah diamankan (lihat Cell)
     */
    public function replaceTab(string $title, string $note, array $headers, array $rows): void
    {
        $tabs = $this->inspect()['tabs'];

        if (! isset($tabs[$title])) {
            $this->createFormattedTab($title);
        }

        $quoted = $this->quote($title);
        $this->send(fn (PendingRequest $r) => $r->post("{$this->base()}/values:batchClear", [
            'ranges' => ["{$quoted}!A".self::DATA_START_ROW.':'.self::LAST_COLUMN],
        ]));

        $data = [['range' => "{$quoted}!A1", 'values' => [[$note], $headers]]];
        foreach (array_chunk($rows, self::CHUNK_ROWS) as $index => $chunk) {
            $data[] = ['range' => "{$quoted}!A".(self::DATA_START_ROW + $index * self::CHUNK_ROWS), 'values' => $chunk];
        }

        $this->send(fn (PendingRequest $r) => $r->post("{$this->base()}/values:batchUpdate", [
            // USER_ENTERED: tanggal, jam, dan angka dikenali Sheets. Rumus dicegah lewat Cell::safe().
            'valueInputOption' => 'USER_ENTERED',
            'data' => $data,
        ]));
    }

    private function createFormattedTab(string $title): void
    {
        $response = $this->send(fn (PendingRequest $r) => $r->post("{$this->base()}:batchUpdate", [
            'requests' => [['addSheet' => ['properties' => ['title' => $title, 'gridProperties' => ['frozenRowCount' => 2]]]]],
        ]));
        $sheetId = $response->json('replies.0.addSheet.properties.sheetId');

        // Judul kolom ditebalkan, dan peringatan muncul sebelum ada yang mengedit (data akan ditimpa sinkronisasi berikutnya).
        $this->send(fn (PendingRequest $r) => $r->post("{$this->base()}:batchUpdate", [
            'requests' => [
                ['repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => 1, 'endRowIndex' => 2],
                    'cell' => ['userEnteredFormat' => ['textFormat' => ['bold' => true], 'backgroundColor' => ['red' => 0.93, 'green' => 0.93, 'blue' => 0.93]]],
                    'fields' => 'userEnteredFormat(textFormat,backgroundColor)',
                ]],
                ['addProtectedRange' => ['protectedRange' => [
                    'range' => ['sheetId' => $sheetId],
                    'description' => 'Salinan otomatis dari aplikasi. Perubahan manual akan ditimpa.',
                    'warningOnly' => true,
                ]]],
            ],
        ]));
    }

    private function send(callable $request): Response
    {
        try {
            $response = $request($this->authorized());
        } catch (ConnectionException $e) {
            throw new SheetsTransientException('Koneksi ke Google Sheets gagal.', previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $this->throwFor($response);
    }

    private function throwFor(Response $response): never
    {
        $status = $response->status();
        $reason = $response->json('error.message') ?? '';

        // Kuota (429) dan gangguan server (5xx) sementara. Selebihnya urusan pengaturan, dan mengulang tidak membantu.
        if ($status === 429 || $status >= 500) {
            throw new SheetsTransientException("Google Sheets sedang tidak bisa dipakai (HTTP {$status}).");
        }

        Log::error('Google Sheets menolak permintaan', ['status' => $status, 'alasan' => $reason]);

        throw new SheetsConfigurationException(match ($status) {
            401 => 'Kunci Google ditolak. Unduh ulang file kunci akun layanan.',
            403 => "Akses ditolak. Bagikan Sheet ke {$this->credentials->clientEmail()} sebagai Editor, dan pastikan Google Sheets API aktif di proyek Google Cloud.",
            404 => 'Spreadsheet tidak ditemukan. Periksa GOOGLE_SHEETS_SPREADSHEET_ID.',
            default => "Google Sheets menolak permintaan (HTTP {$status}).",
        });
    }

    private function authorized(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout($this->timeoutSeconds);
    }

    private function accessToken(): string
    {
        return $this->cache->remember(self::TOKEN_CACHE_KEY.':'.sha1($this->credentials->clientEmail()), 3000, function () {
            try {
                $response = Http::asForm()->timeout($this->timeoutSeconds)->post($this->credentials->tokenUri(), [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->credentials->signedAssertion(self::SCOPE, time()),
                ]);
            } catch (ConnectionException $e) {
                throw new SheetsTransientException('Koneksi ke Google (login akun layanan) gagal.', previous: $e);
            }

            if ($response->failed()) {
                $transient = $response->status() === 429 || $response->status() >= 500;
                Log::error('Login akun layanan Google gagal', ['status' => $response->status(), 'alasan' => $response->json('error_description') ?? $response->json('error')]);

                throw $transient
                    ? new SheetsTransientException('Login akun layanan Google sedang gagal.')
                    : new SheetsConfigurationException('Login akun layanan Google ditolak. Periksa file kunci dan pastikan akunnya aktif.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function base(): string
    {
        return self::API."/{$this->spreadsheetId}";
    }

    /** Nama tab dalam notasi A1: dikutip, dan apostrof di dalamnya digandakan. */
    private function quote(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'";
    }
}
