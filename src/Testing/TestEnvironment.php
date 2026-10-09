<?php

declare(strict_types=1);

namespace B4x\Ksef\Testing;

use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\SigningException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Support\Nip;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Helpers for the KSeF **TEST** environment: create a disposable taxpayer and get credentials for it in one
 * call, so that you can try the SDK (and write integration tests) without any certificate or token of your own.
 *
 * This talks to the TEST-only `/testdata` API. It cannot be pointed at DEMO or production.
 */
final class TestEnvironment
{
    /**
     * Registers a new random taxpayer (a sole proprietor: NIP + PESEL) and generates a self-signed personal
     * certificate for the owner. Random numbers can collide with earlier test data, so a few are tried.
     *
     * @throws ConfigurationException when called for anything but the TEST environment
     * @throws ApiException when KSeF refuses to create the taxpayer
     */
    public static function createTaxpayer(
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        Environment $environment = Environment::Test,
    ): TestTaxpayer {
        if ($environment !== Environment::Test) {
            throw new ConfigurationException('Test taxpayers can only be created on the TEST environment.');
        }

        $transport = new Transport($environment->baseUrl(), $http, $requestFactory, $streamFactory, RetryPolicy::none());

        $lastError = null;
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $nip = self::randomNip();
            $pesel = self::randomPesel();
            try {
                $transport->send(ApiRequest::post('/testdata/person', ['nip' => $nip, 'pesel' => $pesel, 'description' => 'ksef-php test taxpayer', 'isBailiff' => false], null, RetryMode::Never));
            } catch (ApiException $e) {
                if ($e->httpStatus === 400) {
                    $lastError = $e; // most likely "already exists": try another number

                    continue;
                }

                throw $e;
            }
            [$certificate, $key] = self::selfSignedPersonalCertificate($nip);

            return new TestTaxpayer(Nip::unchecked($nip), $pesel, $certificate, $key);
        }

        throw $lastError ?? new ConfigurationException('Could not create a test taxpayer.');
    }

    /**
     * Lets a test taxpayer send invoices with attachments (the consent real taxpayers give the Ministry beforehand).
     *
     * @throws ConfigurationException when called for anything but the TEST environment
     * @throws ApiException when KSeF refuses
     */
    public static function allowAttachments(
        Nip $nip,
        ClientInterface $http,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        Environment $environment = Environment::Test,
    ): void {
        if ($environment !== Environment::Test) {
            throw new ConfigurationException('Attachment consent can only be simulated on the TEST environment.');
        }

        (new Transport($environment->baseUrl(), $http, $requestFactory, $streamFactory, RetryPolicy::none()))
            ->send(ApiRequest::post('/testdata/attachment', ['nip' => $nip->value], null, RetryMode::Never));
    }

    /**
     * @return array{string, string} certificate PEM and private key PEM
     */
    private static function selfSignedPersonalCertificate(string $nip): array
    {
        $config = tempnam(sys_get_temp_dir(), 'ksef-cnf-');
        if ($config === false) {
            throw new SigningException('Cannot create a temporary OpenSSL configuration.');
        }
        file_put_contents($config, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\nprompt = no\n[dn]\n");

        try {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $config]);
            if (!$key instanceof OpenSSLAsymmetricKey) {
                throw new SigningException('Cannot generate a key: ' . (string) openssl_error_string());
            }

            // Subject shaped like a qualified personal certificate: KSeF reads the NIP from serialNumber.
            $subject = ['countryName' => 'PL', 'givenName' => 'Jan', 'surname' => 'Testowy', 'serialNumber' => 'TINPL-' . $nip, 'commonName' => 'Jan Testowy'];
            $keyCopy = $key;
            $csr = openssl_csr_new($subject, $keyCopy, ['digest_alg' => 'sha256', 'config' => $config]);
            $certificate = $csr instanceof OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256', 'config' => $config]) : false;
            if (!$certificate instanceof OpenSSLCertificate) {
                throw new SigningException('Cannot create the test certificate: ' . (string) openssl_error_string());
            }

            $certificatePem = '';
            $keyPem = '';
            openssl_x509_export($certificate, $certificatePem);
            openssl_pkey_export($key, $keyPem, null, ['config' => $config]);
            if (!\is_string($certificatePem) || !\is_string($keyPem)) {
                throw new SigningException('Cannot export the test certificate.');
            }

            return [$certificatePem, $keyPem];
        } finally {
            if (is_file($config)) {
                unlink($config);
            }
        }
    }

    private static function randomNip(): string
    {
        while (true) {
            $digits = (string) random_int(1, 9) . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $i => $weight) {
                $sum += $weight * (int) $digits[$i];
            }
            $candidate = $digits . ($sum % 11);
            if ($sum % 11 < 10 && preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $candidate) === 1) {
                return $candidate;
            }
        }
    }

    private static function randomPesel(): string
    {
        $digits = '900101' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach ([1, 3, 7, 9, 1, 3, 7, 9, 1, 3] as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }

        return $digits . ((10 - $sum % 10) % 10);
    }
}
