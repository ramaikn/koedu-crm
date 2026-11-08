<?php

namespace Tests\Feature\Sheets;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Notifications\NotificationTestCase;

/**
 * Dasar test Google Sheets. Membuat kunci akun layanan palsu (RSA sungguhan, supaya penandatanganan JWT bisa diverifikasi)
 * dan memalsukan seluruh panggilan ke Google. Tidak ada panggilan ke jaringan.
 */
abstract class SheetsTestCase extends NotificationTestCase
{
    protected const SHEET_ID = 'SHEET123';

    protected const EMAIL = 'koedu-sync@proyek-uji.iam.gserviceaccount.com';

    protected string $keyFile;

    protected string $publicKey;

    /** Tab yang "sudah ada" di spreadsheet palsu: judul => sheetId. */
    protected array $googleTabs = ['Jadwal' => 11, 'Client' => 12, 'Paket Client' => 13];

    /** Bila diisi, semua panggilan ke Sheets API (bukan token) dijawab dengan status ini. */
    protected ?int $googleFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('array')->flush();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        $this->keyFile = sys_get_temp_dir().'/koedu-sheets-test-'.uniqid().'.json';
        file_put_contents($this->keyFile, json_encode([
            'type' => 'service_account',
            'client_email' => self::EMAIL,
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'koedu.sheets.enabled' => true,
            'koedu.sheets.spreadsheet_id' => self::SHEET_ID,
            'koedu.sheets.credentials_path' => $this->keyFile,
            'koedu.sheets.months_back' => 12,
            'koedu.sheets.max_rows' => 20000,
            'app.name' => 'Koedu CRM',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    /** Palsukan Google. Panggil sebelum kode yang menulis ke Sheets. */
    protected function fakeGoogle(): void
    {
        Http::fake(fn (Request $request) => $this->googleResponse($request));
    }

    private function googleResponse(Request $request)
    {
        $url = $request->url();

        if (str_contains($url, 'oauth2.googleapis.com')) {
            return Http::response(['access_token' => 'token-uji', 'expires_in' => 3600]);
        }
        if ($this->googleFailure !== null) {
            return Http::response(['error' => ['message' => 'gagal uji']], $this->googleFailure);
        }
        if ($request->method() === 'GET') {
            return Http::response([
                'properties' => ['title' => 'Koedu Uji'],
                'sheets' => collect($this->googleTabs)->map(fn ($id, $title) => ['properties' => ['sheetId' => $id, 'title' => $title]])->values()->all(),
            ]);
        }
        if (str_contains($url, 'values:')) {
            return Http::response([]);
        }

        // batchUpdate struktur: addSheet mengembalikan id tab baru.
        return Http::response(['replies' => [['addSheet' => ['properties' => ['sheetId' => 99]]]]]);
    }

    /** @return list<Request> semua permintaan ke Sheets API (tanpa login token), berurutan */
    protected function sheetsRequests(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])
            ->reject(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com'))
            ->values()->all();
    }

    protected function tokenRequests(): int
    {
        return Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com'))->count();
    }

    protected function fakeQueue(): void
    {
        Queue::fake();
        Storage::fake('local');
    }
}
