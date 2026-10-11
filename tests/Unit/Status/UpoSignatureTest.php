<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Status;

use B4x\Ksef\Status\Upo;
use B4x\Ksef\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

/**
 * Uses a real UPO issued by the KSeF TEST environment (fictitious taxpayer, no real data).
 */
final class UpoSignatureTest extends TestCase
{
    public function testTheMinistrysSignatureOnARealUpoVerifies(): void
    {
        $check = $this->upo()->verifySignature();

        self::assertTrue($check->valid, implode('; ', $check->problems));
        self::assertFalse($check->trusted, 'Nothing was pinned, so authenticity is not claimed.');
        self::assertStringContainsString('Ministerstwo Finansów', (string) $check->signerSubject);
        self::assertNotNull($check->signerFingerprint);
        self::assertNotNull($check->certificateValidTo);
    }

    public function testAChangedDocumentFailsTheDigestCheck(): void
    {
        $tampered = new Upo(str_replace('<TrybWysylki>Online', '<TrybWysylki>Offline', $this->upo()->xml), '');

        $check = $tampered->verifySignature();

        self::assertFalse($check->valid);
        self::assertStringContainsString('does not match', implode(' ', $check->problems));
    }

    public function testAChangedSignatureValueFails(): void
    {
        $xml = (string) preg_replace('#<SignatureValue>(.{4})#', '<SignatureValue>AAAA', $this->upo()->xml, 1);

        self::assertFalse((new Upo($xml, ''))->verifySignature()->valid);
    }

    public function testAMissingSignatureIsNotValid(): void
    {
        $xml = (string) preg_replace('#<Signature .*</Signature>#s', '', $this->upo()->xml);

        $check = (new Upo($xml, ''))->verifySignature();

        self::assertFalse($check->valid);
        self::assertStringContainsString('exactly one', implode(' ', $check->problems));
    }

    public function testAPinnedCertificateMakesTheSignerTrusted(): void
    {
        self::assertSame(1, preg_match('#<X509Certificate>(.*?)</X509Certificate>#s', $this->upo()->xml, $m));
        $signerPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $m[1]) ?? '', 64, "\n") . "-----END CERTIFICATE-----\n";
        $other = $this->selfSignedPem();

        self::assertTrue($this->upo()->verifySignature($signerPem)->trusted, 'The pinned certificate is the signer.');
        self::assertFalse($this->upo()->verifySignature($other)->trusted, 'An unrelated certificate is not a reason to trust.');
        self::assertTrue($this->upo()->verifySignature($other)->valid, 'The integrity result does not depend on trust.');
    }

    private function upo(): Upo
    {
        return new Upo((string) file_get_contents(__DIR__ . '/../../Fixtures/upo-ksef-test.xml'), '');
    }

    private function selfSignedPem(): string
    {
        return TestPki::selfSigned()['certificatePem'];
    }
}
