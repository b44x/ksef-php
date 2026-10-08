<?php

declare(strict_types=1);

namespace Ksef\Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/** Generates throw-away keys and self-signed certificates for tests. Nothing here is a secret. */
final class TestPki
{
    private static ?string $config = null;

    /**
     * @return array{privateKeyPem: string, certificatePem: string, certificateDer: string}
     */
    public static function selfSigned(string $type = 'rsa', int $bits = 2048, string $commonName = 'Test'): array
    {
        return self::issue(['countryName' => 'PL', 'commonName' => $commonName], $type, $bits);
    }

    /**
     * Certificate shaped like a KSeF test personal signature (givenName, surname, serialNumber with NIP).
     *
     * @return array{privateKeyPem: string, certificatePem: string, certificateDer: string}
     */
    public static function personal(string $nip, string $type = 'rsa'): array
    {
        return self::issue([
            'countryName' => 'PL',
            'givenName' => 'Jan',
            'surname' => 'Kowalski',
            'serialNumber' => 'TINPL-' . $nip,
            'commonName' => 'Jan Kowalski',
        ], $type);
    }

    /**
     * Certificate shaped like a company seal (organizationName, organizationIdentifier).
     *
     * @return array{privateKeyPem: string, certificatePem: string, certificateDer: string}
     */
    public static function seal(string $nip, string $type = 'rsa'): array
    {
        return self::issue([
            'countryName' => 'PL',
            'organizationName' => 'Test sp. z o.o.',
            'organizationIdentifier' => 'VATPL-' . $nip,
            'commonName' => 'Test Seal',
        ], $type);
    }

    /**
     * @param array<string, string> $dn
     *
     * @return array{privateKeyPem: string, certificatePem: string, certificateDer: string}
     */
    private static function issue(array $dn, string $type, int $bits = 2048): array
    {
        $options = $type === 'ec'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits];
        $options['config'] = self::config();

        $key = openssl_pkey_new($options);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Cannot generate a test key: ' . openssl_error_string());
        }

        // openssl_csr_new() takes the key by reference, so it receives a copy.
        $keyCopy = $key;
        $csr = openssl_csr_new($dn, $keyCopy, ['digest_alg' => 'sha256', 'config' => self::config()]);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Cannot create a CSR: ' . openssl_error_string());
        }

        $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256', 'config' => self::config()]);
        if (!$certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('Cannot sign the certificate: ' . openssl_error_string());
        }

        $exportedKey = '';
        $exportedCertificate = '';
        openssl_pkey_export($key, $exportedKey, null, ['config' => self::config()]);
        openssl_x509_export($certificate, $exportedCertificate);
        $privateKeyPem = \is_string($exportedKey) ? $exportedKey : throw new RuntimeException('Key export failed.');
        $certificatePem = \is_string($exportedCertificate) ? $exportedCertificate : throw new RuntimeException('Certificate export failed.');

        $der = base64_decode(trim((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $certificatePem)), true);

        return ['privateKeyPem' => $privateKeyPem, 'certificatePem' => $certificatePem, 'certificateDer' => (string) $der];
    }

    private static function config(): string
    {
        if (self::$config === null) {
            $path = sys_get_temp_dir() . '/ksef-php-test-openssl.cnf';
            file_put_contents($path, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\n");
            self::$config = $path;
        }

        return self::$config;
    }
}
