<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Qr;

use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\SigningException;
use B4x\Ksef\Exception\ValidationException;
use B4x\Ksef\Qr\OfflineCertificate;
use B4x\Ksef\Qr\VerificationLinks;
use B4x\Ksef\Support\Nip;
use B4x\Ksef\Tests\Support\TestPki;
use DateTimeImmutable;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerificationLinksTest extends TestCase
{
    public function testInvoiceLinkMatchesTheOfficialExample(): void
    {
        // Example from the KSeF documentation (QR codes, KOD I), TEST environment.
        $hash = base64_encode((string) base64_decode(strtr('UtQp9Gpc51y-u3xApZjIjgkpZ01js-J8KflSPW8WzIE', '-_', '+/') . '=', true));

        $url = (new VerificationLinks(Environment::Test))->invoiceUrlFromHash(Nip::unchecked('1111111111'), new DateTimeImmutable('2026-02-01'), $hash);

        self::assertSame('https://qr-test.ksef.mf.gov.pl/invoice/1111111111/01-02-2026/UtQp9Gpc51y-u3xApZjIjgkpZ01js-J8KflSPW8WzIE', $url);
    }

    public function testHostsPerEnvironment(): void
    {
        $nip = Nip::unchecked('1111111111');
        $date = new DateTimeImmutable('2026-02-01');
        $hash = base64_encode(hash('sha256', 'x', true));

        self::assertStringStartsWith('https://qr-demo.ksef.mf.gov.pl/invoice/', (new VerificationLinks(Environment::Demo))->invoiceUrlFromHash($nip, $date, $hash));
        self::assertStringStartsWith('https://qr.ksef.mf.gov.pl/invoice/', (new VerificationLinks(Environment::Production))->invoiceUrlFromHash($nip, $date, $hash));
    }

    public function testRsaCertificateLinkIsSignedWithPssOverThePathWithoutScheme(): void
    {
        $pki = TestPki::seal('1111111111');
        $certificate = new OfflineCertificate($pki['certificatePem'], $pki['privateKeyPem']);
        $hash = base64_encode(hash('sha256', 'invoice', true));

        $url = (new VerificationLinks(Environment::Test))->certificateUrl(ContextIdentifier::nip('1111111111'), Nip::unchecked('1111111111'), $hash, $certificate);

        $prefix = 'https://qr-test.ksef.mf.gov.pl/certificate/Nip/1111111111/1111111111/' . $certificate->serialNumber() . '/';
        self::assertStringStartsWith($prefix, $url);
        $lastSlash = (int) strrpos($url, '/');
        $signed = substr($url, \strlen('https://'), $lastSlash - \strlen('https://'));
        $signature = substr($url, $lastSlash + 1);
        self::assertStringNotContainsString('=', $signature);

        $public = PublicKeyLoader::load($pki['certificatePem']);
        self::assertInstanceOf(RSA\PublicKey::class, $public);
        $verifier = $public->withPadding(RSA::SIGNATURE_PSS);
        self::assertInstanceOf(RSA\PublicKey::class, $verifier);
        $verifier = $verifier->withHash('sha256');
        self::assertInstanceOf(RSA\PublicKey::class, $verifier);
        $verifier = $verifier->withMGFHash('sha256');
        self::assertInstanceOf(RSA\PublicKey::class, $verifier);
        $verifier = $verifier->withSaltLength(32);
        self::assertInstanceOf(RSA\PublicKey::class, $verifier);
        self::assertTrue($verifier->verify($signed, (string) base64_decode(strtr($signature, '-_', '+/'), true)));
        self::assertFalse($verifier->verify($signed . 'x', (string) base64_decode(strtr($signature, '-_', '+/'), true)));
    }

    public function testEcCertificateLinkUsesFixedWidthSignature(): void
    {
        $pki = TestPki::seal('1111111111', 'ec');
        $certificate = new OfflineCertificate($pki['certificatePem'], $pki['privateKeyPem']);

        $signature = $certificate->signBase64Url('qr-test.ksef.mf.gov.pl/certificate/x');
        $raw = (string) base64_decode(strtr($signature, '-_', '+/'), true);

        self::assertSame(64, \strlen($raw), 'R||S with 32 bytes each');
        $der = $this->toDer(substr($raw, 0, 32), substr($raw, 32));
        $key = openssl_pkey_get_public($pki['certificatePem']);
        self::assertNotFalse($key);
        self::assertSame(1, openssl_verify('qr-test.ksef.mf.gov.pl/certificate/x', $der, $key, OPENSSL_ALGO_SHA256));
    }

    public function testSerialNumberIsUpperCaseHex(): void
    {
        $pki = TestPki::seal('1111111111');

        self::assertMatchesRegularExpression('/^[0-9A-F]+$/', (new OfflineCertificate($pki['certificatePem'], $pki['privateKeyPem']))->serialNumber());
    }

    public function testMismatchedKeyIsRefused(): void
    {
        $a = TestPki::seal('1111111111');
        $b = TestPki::seal('1111111111');

        $this->expectException(SigningException::class);
        new OfflineCertificate($a['certificatePem'], $b['privateKeyPem']);
    }

    #[DataProvider('labels')]
    public function testLabelIsTheKsefNumberOrOffline(?string $number, string $expected): void
    {
        self::assertSame($expected, (new VerificationLinks(Environment::Test))->label($number));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function labels(): iterable
    {
        yield 'offline' => [null, 'OFFLINE'];
        yield 'known number' => ['5265877635-20250826-0100001AF629-AF', '5265877635-20250826-0100001AF629-AF'];
    }

    public function testInvalidKsefNumberAndHashAreRejected(): void
    {
        $links = new VerificationLinks(Environment::Test);
        try {
            $links->label('5265877635-20250826-0100001AF629-00');
            self::fail('Expected ValidationException');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ValidationException::class);
        $links->invoiceUrlFromHash(Nip::unchecked('1111111111'), new DateTimeImmutable('2026-02-01'), '***');
    }

    private function toDer(string $r, string $s): string
    {
        $encode = static function (string $integer): string {
            $integer = ltrim($integer, "\x00");
            if ($integer === '' || (\ord($integer[0]) & 0x80) !== 0) {
                $integer = "\x00" . $integer;
            }

            return "\x02" . \chr(\strlen($integer)) . $integer;
        };
        $body = $encode($r) . $encode($s);

        return "\x30" . \chr(\strlen($body)) . $body;
    }
}
