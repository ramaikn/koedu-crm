<?php

namespace Tests\Feature\Sheets;

use App\Services\Sheets\Cell;
use App\Services\Sheets\GoogleSheetsClient;
use App\Services\Sheets\ServiceAccountCredentials;
use App\Services\Sheets\SheetsConfigurationException;
use App\Services\Sheets\SheetsTransientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ClientAndCredentialsTest extends SheetsTestCase
{
    private function client(): GoogleSheetsClient
    {
        return app(GoogleSheetsClient::class);
    }

    private function decode(string $part): array
    {
        return json_decode(base64_decode(strtr($part, '-_', '+/')), true);
    }

    // --- Kunci akun layanan ---

    public function test_credentials_reject_missing_or_invalid_files(): void
    {
        foreach ([null, '', '/tidak/ada.json'] as $path) {
            try {
                ServiceAccountCredentials::fromFile($path);
                $this->fail('Seharusnya gagal.');
            } catch (SheetsConfigurationException $e) {
                $this->assertStringContainsString('GOOGLE_SHEETS_CREDENTIALS_PATH', $e->getMessage());
            }
        }

        $notAKey = sys_get_temp_dir().'/koedu-bukan-kunci-'.uniqid().'.json';
        foreach (['bukan json', json_encode(['type' => 'authorized_user']), json_encode(['type' => 'service_account', 'client_email' => 'a@b'])] as $content) {
            file_put_contents($notAKey, $content);
            try {
                ServiceAccountCredentials::fromFile($notAKey);
                $this->fail('Seharusnya ditolak: '.$content);
            } catch (SheetsConfigurationException $e) {
                $this->assertStringContainsString('bukan kunci akun layanan', $e->getMessage());
            }
        }
        unlink($notAKey);
    }

    public function test_signed_assertion_is_a_valid_rs256_jwt(): void
    {
        $credentials = ServiceAccountCredentials::fromFile($this->keyFile);

        [$header, $claims, $signature] = explode('.', $credentials->signedAssertion('https://www.googleapis.com/auth/spreadsheets', 1_762_600_000));

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $this->decode($header));
        $this->assertSame([
            'iss' => self::EMAIL,
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => 1_762_600_000,
            'exp' => 1_762_603_600,
        ], $this->decode($claims));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/')), $this->publicKey, OPENSSL_ALGO_SHA256), 'tanda tangan harus sah');
        $this->assertSame(self::EMAIL, $credentials->clientEmail());
    }

    // --- Pengamanan sel ---

    public function test_cells_cannot_become_formulas_and_phone_numbers_stay_text(): void
    {
        foreach (['=HYPERLINK("http://jahat","klik")', '+1+1', '-2+3', '@SUM(A1)'] as $evil) {
            $this->assertSame("'{$evil}", Cell::safe($evil), $evil);
        }
        $this->assertSame('Budi Santoso', Cell::safe('Budi Santoso'));
        $this->assertSame(5, Cell::safe(5));
        $this->assertSame(1.5, Cell::safe(1.5));
        $this->assertSame('', Cell::safe(null));
        $this->assertSame("'6281234567890", Cell::text('6281234567890'));
        $this->assertSame('', Cell::text(null));
        $this->assertSame(["'=x", 3, 'ok'], Cell::row(['=x', 3, 'ok']));
    }

    // --- Klien ---

    public function test_replace_tab_clears_old_data_then_writes_note_headers_and_rows(): void
    {
        $this->fakeGoogle();

        $this->client()->replaceTab('Jadwal', 'Catatan', ['A', 'B'], [[1, 'x'], [2, 'y']]);

        [$inspect, $clear, $write] = $this->sheetsRequests();
        $this->assertSame('GET', $inspect->method());
        $this->assertTrue($inspect->hasHeader('Authorization', 'Bearer token-uji'));
        $this->assertStringContainsString('/spreadsheets/SHEET123/values:batchClear', $clear->url());
        $this->assertSame(["'Jadwal'!A3:ZZ"], $clear['ranges']);
        $this->assertStringContainsString('/values:batchUpdate', $write->url());
        $this->assertSame('USER_ENTERED', $write['valueInputOption']);
        $this->assertSame([
            ['range' => "'Jadwal'!A1", 'values' => [['Catatan'], ['A', 'B']]],
            ['range' => "'Jadwal'!A3", 'values' => [[1, 'x'], [2, 'y']]],
        ], $write['data']);
    }

    public function test_token_request_uses_jwt_bearer_grant_and_is_cached(): void
    {
        $this->fakeGoogle();

        $this->client()->replaceTab('Jadwal', 'c', ['A'], []);
        $this->client()->inspect();

        $this->assertSame(1, $this->tokenRequests(), 'token dipakai ulang');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com')
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && substr_count($r['assertion'], '.') === 2);
    }

    public function test_missing_tab_is_created_formatted_and_protected_with_a_warning(): void
    {
        $this->googleTabs = ['Client' => 12];
        $this->fakeGoogle();

        $this->client()->replaceTab('Jadwal', 'c', ['A'], [[1]]);

        [, $add, $format] = $this->sheetsRequests();
        $this->assertStringContainsString('SHEET123:batchUpdate', $add->url());
        $this->assertSame('Jadwal', $add['requests'][0]['addSheet']['properties']['title']);
        $this->assertSame(2, $add['requests'][0]['addSheet']['properties']['gridProperties']['frozenRowCount']);
        $this->assertSame(99, $format['requests'][0]['repeatCell']['range']['sheetId']);
        $this->assertTrue($format['requests'][0]['repeatCell']['cell']['userEnteredFormat']['textFormat']['bold']);
        $protected = $format['requests'][1]['addProtectedRange']['protectedRange'];
        $this->assertTrue($protected['warningOnly'], 'hanya peringatan, bukan penguncian: admin Sheet tetap bisa mengedit');
    }

    public function test_existing_tab_is_not_recreated(): void
    {
        $this->fakeGoogle();

        $this->client()->replaceTab('Jadwal', 'c', ['A'], []);

        $this->assertCount(3, $this->sheetsRequests(), 'baca, hapus, tulis');
    }

    public function test_large_tabs_are_written_in_chunks_with_correct_start_rows(): void
    {
        $this->fakeGoogle();
        $rows = array_map(fn ($i) => [$i], range(1, 4500));

        $this->client()->replaceTab('Jadwal', 'c', ['A'], $rows);

        $data = $this->sheetsRequests()[2]['data'];
        $this->assertSame(["'Jadwal'!A1", "'Jadwal'!A3", "'Jadwal'!A2003", "'Jadwal'!A4003"], array_column($data, 'range'));
        $this->assertCount(2000, $data[1]['values']);
        $this->assertCount(500, $data[3]['values']);
    }

    public function test_tab_titles_with_apostrophes_are_quoted(): void
    {
        $this->googleTabs = ["Budi's" => 5];
        $this->fakeGoogle();

        $this->client()->replaceTab("Budi's", 'c', ['A'], [[1]]);

        $this->assertSame(["'Budi''s'!A3:ZZ"], $this->sheetsRequests()[1]['ranges']);
    }

    public function test_inspect_returns_title_and_tabs(): void
    {
        $this->fakeGoogle();

        $this->assertSame(['title' => 'Koedu Uji', 'tabs' => ['Jadwal' => 11, 'Client' => 12, 'Paket Client' => 13]], $this->client()->inspect());
        $this->assertSame('https://docs.google.com/spreadsheets/d/SHEET123', $this->client()->url());
    }

    // --- Pemetaan kesalahan ---

    public function test_configuration_errors_are_not_retryable_and_say_what_to_fix(): void
    {
        $cases = [
            403 => [self::EMAIL, 'sebagai Editor'],
            404 => ['GOOGLE_SHEETS_SPREADSHEET_ID'],
            401 => ['Kunci Google ditolak'],
            400 => ['HTTP 400'],
        ];
        foreach ($cases as $status => $fragments) {
            $this->googleFailure = $status;
            $this->fakeGoogle();
            try {
                $this->client()->inspect();
                $this->fail("HTTP {$status} seharusnya gagal.");
            } catch (SheetsConfigurationException $e) {
                foreach ($fragments as $fragment) {
                    $this->assertStringContainsString($fragment, $e->getMessage(), "HTTP {$status}");
                }
            }
        }
    }

    public function test_quota_and_server_errors_are_transient(): void
    {
        foreach ([429, 500, 503] as $status) {
            $this->googleFailure = $status;
            $this->fakeGoogle();
            try {
                $this->client()->inspect();
                $this->fail("HTTP {$status} seharusnya gagal.");
            } catch (SheetsTransientException $e) {
                $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
            }
        }
    }

    public function test_connection_failures_are_transient(): void
    {
        Http::fake(fn (Request $r) => str_contains($r->url(), 'oauth2') ? Http::response(['access_token' => 't']) : throw new ConnectionException('timeout'));

        $this->expectException(SheetsTransientException::class);
        $this->client()->inspect();
    }

    public function test_rejected_service_account_login_is_a_configuration_error_but_google_outage_is_transient(): void
    {
        // Satu urutan jawaban: stub Http::fake yang dipanggil ulang menumpuk, bukan menggantikan.
        Http::fake(['oauth2.googleapis.com/*' => Http::sequence()
            ->push(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)
            ->push([], 503)]);
        try {
            $this->client()->inspect();
            $this->fail('Seharusnya ditolak.');
        } catch (SheetsConfigurationException $e) {
            $this->assertStringContainsString('Login akun layanan Google ditolak', $e->getMessage());
        }

        $this->expectException(SheetsTransientException::class);
        $this->client()->inspect();
    }
}
