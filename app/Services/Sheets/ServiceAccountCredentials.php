<?php

namespace App\Services\Sheets;

/**
 * Kunci akun layanan Google (file JSON dari Google Cloud). Hanya membaca file dan menandatangani JWT
 * untuk ditukar dengan token akses. Isi kunci tidak pernah dicatat atau ditampilkan.
 */
final class ServiceAccountCredentials
{
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private function __construct(
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly string $tokenUri,
    ) {}

    /** @throws SheetsConfigurationException bila file tidak ada, tidak terbaca, atau bukan kunci akun layanan */
    public static function fromFile(?string $path): self
    {
        if ($path === null || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new SheetsConfigurationException('File kunci Google tidak ditemukan. Periksa GOOGLE_SHEETS_CREDENTIALS_PATH.');
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || ($json['type'] ?? null) !== 'service_account' || empty($json['client_email']) || empty($json['private_key'])) {
            throw new SheetsConfigurationException('File kunci Google bukan kunci akun layanan (service account) yang sah.');
        }

        return new self($json['client_email'], $json['private_key'], $json['token_uri'] ?? self::DEFAULT_TOKEN_URI);
    }

    /** Alamat email akun layanan. Sheet harus dibagikan ke alamat ini sebagai Editor. */
    public function clientEmail(): string
    {
        return $this->clientEmail;
    }

    public function tokenUri(): string
    {
        return $this->tokenUri;
    }

    /** JWT bertanda tangan RS256 untuk ditukar dengan token akses (grant jwt-bearer). */
    public function signedAssertion(string $scope, int $issuedAt): string
    {
        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode([
                'iss' => $this->clientEmail,
                'scope' => $scope,
                'aud' => $this->tokenUri,
                'iat' => $issuedAt,
                'exp' => $issuedAt + 3600,
            ])),
        ];

        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new SheetsConfigurationException('Kunci pribadi Google tidak bisa dipakai untuk menandatangani. Unduh ulang file kunci dari Google Cloud.');
        }

        return implode('.', [...$segments, $this->base64Url($signature)]);
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
