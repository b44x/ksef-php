<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Certificates;

use B4x\Ksef\Certificates\CsrGenerator;
use B4x\Ksef\Certificates\EnrollmentData;
use B4x\Ksef\Certificates\KeyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrGeneratorTest extends TestCase
{
    /**
     * @return iterable<string, array{KeyType, int, int}>
     */
    public static function keyTypes(): iterable
    {
        yield 'EC P-256 (recommended)' => [KeyType::EcP256, OPENSSL_KEYTYPE_EC, 256];
        yield 'RSA 2048' => [KeyType::Rsa2048, OPENSSL_KEYTYPE_RSA, 2048];
    }

    #[DataProvider('keyTypes')]
    public function testBuildsASignedPkcs10RequestWithTheDictatedSubject(KeyType $keyType, int $opensslType, int $bits): void
    {
        $subject = new EnrollmentData('Jan Kowalski', 'PL', ['Jan', 'Maria'], 'Kowalski', 'TINPL-5265877635', 'uid-1');

        $csr = (new CsrGenerator())->generate($subject, $keyType);

        $der = base64_decode($csr->csrBase64, true);
        self::assertIsString($der);
        $pem = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE REQUEST-----\n";

        $parsed = openssl_csr_get_subject($pem);
        self::assertIsArray($parsed);
        self::assertSame('Jan Kowalski', $parsed['CN']);
        self::assertSame('PL', $parsed['C']);
        self::assertSame('Kowalski', $parsed['SN']);
        self::assertSame('TINPL-5265877635', $parsed['serialNumber']);
        self::assertSame(['Jan', 'Maria'], $parsed['GN'], 'every given name is its own attribute');

        // The request carries the public key that matches the returned private key, and its signature verifies.
        $public = openssl_csr_get_public_key($pem);
        self::assertNotFalse($public);
        $details = openssl_pkey_get_details($public);
        self::assertIsArray($details);
        self::assertSame($opensslType, $details['type']);
        self::assertSame($bits, $details['bits']);
        $private = openssl_pkey_get_private($csr->privateKeyPem);
        self::assertNotFalse($private);
        $privateDetails = openssl_pkey_get_details($private);
        self::assertIsArray($privateDetails);
        self::assertSame($details['key'], $privateDetails['key']);
        self::assertStringContainsString('verify OK', (string) shell_exec('printf %s ' . escapeshellarg($pem) . ' | openssl req -noout -verify 2>&1'));
    }

    public function testSealSubjectUsesOrganizationAttributesAndPolishCharactersSurvive(): void
    {
        $subject = new EnrollmentData('Zażółć Gęślą Sp. z o.o.', 'PL', organizationName: 'Zażółć Gęślą Sp. z o.o.', organizationIdentifier: 'VATPL-5265877635');

        $csr = (new CsrGenerator())->generate($subject);

        $pem = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split($csr->csrBase64, 64, "\n") . "-----END CERTIFICATE REQUEST-----\n";
        $parsed = openssl_csr_get_subject($pem);
        self::assertIsArray($parsed);
        self::assertSame('Zażółć Gęślą Sp. z o.o.', $parsed['O']);
        self::assertSame('VATPL-5265877635', $parsed['organizationIdentifier']);
    }

    public function testThePrivateKeyIsHiddenFromDumps(): void
    {
        $csr = (new CsrGenerator())->generate(new EnrollmentData('X', 'PL'));

        ob_start();
        var_dump($csr);
        self::assertStringNotContainsString('PRIVATE KEY', (string) ob_get_clean());
    }
}
