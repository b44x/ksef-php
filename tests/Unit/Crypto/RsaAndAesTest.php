<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Crypto;

use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Crypto\KeyUsage;
use B4x\Ksef\Crypto\KsefTokenEncryptor;
use B4x\Ksef\Crypto\PublicKeyCertificate;
use B4x\Ksef\Crypto\PublicKeyProvider;
use B4x\Ksef\Crypto\RsaOaepEncryptor;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Exception\EncryptionException;
use B4x\Ksef\Tests\Support\TestPki;
use DateTimeImmutable;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;

final class RsaAndAesTest extends TestCase
{
    public function testSha256Base64(): void
    {
        self::assertSame('dQnlvaDHYtK6x/kNdYtbImP6Acy8VCq1498WO+CObKk=', Digest::sha256Base64('hello world!'));
        self::assertSame(hash('sha256', 'abc'), Digest::sha256Hex('abc'));
    }

    public function testAesMatchesIndependentlyComputedVector(): void
    {
        // Ciphertext produced with: printf 'KSeF test payload' | openssl enc -aes-256-cbc -K <key> -iv <iv> | base64
        $key = hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');
        $iv = hex2bin('0f0e0d0c0b0a09080706050403020100');
        self::assertIsString($key);
        self::assertIsString($iv);
        $expected = base64_decode('4fc7mGqh2icheisp1RzMtCxe/bts5ehI/JGWeji9cvk=', true);

        $encryption = SessionEncryption::fromMaterial($key, $iv);
        $ciphertext = $encryption->encrypt('KSeF test payload');

        self::assertSame(0, \strlen($ciphertext) % 16);
        self::assertSame('KSeF test payload', $encryption->decrypt($ciphertext));
        self::assertNotFalse($expected);
        self::assertSame('KSeF test payload', $encryption->decrypt($expected));
    }

    public function testGeneratedMaterialIsRandomAndHidden(): void
    {
        $a = SessionEncryption::generate();
        $b = SessionEncryption::generate();

        self::assertNotSame($a->encrypt('x'), $b->encrypt('x'));
        self::assertSame(['key' => '***', 'iv' => '***'], $a->__debugInfo());
    }

    public function testRejectsWrongKeyOrIvLength(): void
    {
        $this->expectException(EncryptionException::class);
        SessionEncryption::fromMaterial('short', 'short');
    }

    public function testDecryptingGarbageFails(): void
    {
        $this->expectException(EncryptionException::class);
        SessionEncryption::generate()->decrypt(str_repeat("\x01", 16));
    }

    public function testRsaOaepSha256RoundTripAndDistinctCiphertexts(): void
    {
        [$certificate, $private] = $this->certificate(KeyUsage::SymmetricKeyEncryption);
        $rsa = new RsaOaepEncryptor();

        $first = $rsa->encrypt('secret', $certificate);
        $second = $rsa->encrypt('secret', $certificate);

        self::assertNotSame($first, $second, 'OAEP must be randomized');
        self::assertSame('secret', $this->decrypt($private, $first));
    }

    public function testSessionEncryptionInfoWrapsTheKeyForKsef(): void
    {
        [$certificate, $private] = $this->certificate(KeyUsage::SymmetricKeyEncryption);
        $session = SessionEncryption::fromMaterial(str_repeat('K', 32), str_repeat('I', 16));

        $info = $session->encryptionInfo($this->provider($certificate));

        self::assertSame(str_repeat('K', 32), $this->decrypt($private, (string) base64_decode($info['encryptedSymmetricKey'], true)));
        self::assertSame(str_repeat('I', 16), base64_decode($info['initializationVector'], true));
        self::assertSame($certificate->publicKeyId, $info['publicKeyId']);
    }

    public function testTokenEncryptionUsesTokenPipeTimestamp(): void
    {
        [$certificate, $private] = $this->certificate(KeyUsage::KsefTokenEncryption);

        $result = (new KsefTokenEncryptor($this->provider($certificate)))->encrypt('20260101-EC-ABC|token-value', 1_767_225_600_000);

        self::assertSame('20260101-EC-ABC|token-value|1767225600000', $this->decrypt($private, (string) base64_decode($result['encryptedToken'], true)));
    }

    public function testRejectsWeakRsaKeys(): void
    {
        $pki = TestPki::selfSigned('rsa', 1024);
        $certificate = new PublicKeyCertificate($pki['certificateDer'], 'id', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), [KeyUsage::SymmetricKeyEncryption]);

        $this->expectException(EncryptionException::class);
        (new RsaOaepEncryptor())->encrypt('x', $certificate);
    }

    public function testCertificateValidityAndUsage(): void
    {
        [$certificate] = $this->certificate(KeyUsage::KsefTokenEncryption);

        self::assertTrue($certificate->supports(KeyUsage::KsefTokenEncryption));
        self::assertFalse($certificate->supports(KeyUsage::SymmetricKeyEncryption));
        self::assertTrue($certificate->isValidAt(new DateTimeImmutable()));
        self::assertFalse($certificate->isValidAt(new DateTimeImmutable('+10 days')));
    }

    public function testFromApiRejectsBrokenPayloads(): void
    {
        $this->expectException(EncryptionException::class);
        PublicKeyCertificate::fromApi(['certificate' => '%%%', 'publicKeyId' => 'x', 'validFrom' => '2025-01-01T00:00:00+00:00', 'validTo' => '2027-01-01T00:00:00+00:00', 'usage' => []]);
    }

    /**
     * @return array{PublicKeyCertificate, string}
     */
    private function certificate(KeyUsage $usage): array
    {
        $pki = TestPki::selfSigned();

        return [
            new PublicKeyCertificate($pki['certificateDer'], 'test-key-id', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), [$usage]),
            $pki['privateKeyPem'],
        ];
    }

    private function provider(PublicKeyCertificate $certificate): PublicKeyProvider
    {
        return new class ($certificate) implements PublicKeyProvider {
            public function __construct(private readonly PublicKeyCertificate $certificate) {}

            public function get(KeyUsage $usage): PublicKeyCertificate
            {
                return $this->certificate;
            }
        };
    }

    private function decrypt(string $privateKeyPem, string $ciphertext): string
    {
        $key = PublicKeyLoader::load($privateKeyPem);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);

        $key = $key->withPadding(RSA::ENCRYPTION_OAEP);
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);
        $key = $key->withMGFHash('sha256');
        self::assertInstanceOf(RSA\PrivateKey::class, $key);

        return (string) $key->decrypt($ciphertext);
    }
}
