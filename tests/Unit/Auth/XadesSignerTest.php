<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Auth;

use B4x\Ksef\Auth\AllowedIps;
use B4x\Ksef\Auth\AuthTokenRequestXml;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\SubjectIdentifierType;
use B4x\Ksef\Exception\SerializationException;
use B4x\Ksef\Exception\SigningException;
use B4x\Ksef\Signing\OpenSslXadesSigner;
use B4x\Ksef\Tests\Support\MutableClock;
use B4x\Ksef\Tests\Support\TestPki;
use B4x\Ksef\Tests\Support\XmlDsigVerifier;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class XadesSignerTest extends TestCase
{
    private const CHALLENGE = '20260101-CR-ABCDEF0123-0123456789-AB';

    /**
     * @return iterable<string, array{string}>
     */
    public static function keyTypes(): iterable
    {
        yield 'rsa' => ['rsa'];
        yield 'ec' => ['ec'];
    }

    #[DataProvider('keyTypes')]
    public function testProducesAVerifiableEnvelopedXadesSignature(string $type): void
    {
        $pki = TestPki::seal('5265877635', $type);
        $signer = new OpenSslXadesSigner($pki['certificatePem'], $pki['privateKeyPem'], null, new MutableClock('2026-03-04T05:06:07+00:00'));
        $unsigned = (new AuthTokenRequestXml())->build(self::CHALLENGE, ContextIdentifier::nip('5265877635'), SubjectIdentifierType::CertificateSubject);

        $signed = $signer->sign($unsigned);

        self::assertSame([], XmlDsigVerifier::verify($signed));

        $document = new DOMDocument();
        $document->loadXML($signed);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');
        self::assertSame('2026-03-04T05:06:07Z', $xpath->evaluate('string(//xades:SigningTime)'));
        self::assertSame(base64_encode(hash('sha256', $pki['certificateDer'], true)), $xpath->evaluate('string(//xades:CertDigest/ds:DigestValue)'));
        self::assertSame(base64_encode($pki['certificateDer']), $xpath->evaluate('string(//ds:X509Certificate)'));
        self::assertSame($type === 'ec' ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256' : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', $xpath->evaluate('string(//ds:SignatureMethod/@Algorithm)'));
        self::assertSame($signer->certificateFingerprint(), hash('sha256', $pki['certificateDer']));
    }

    public function testTamperingBreaksTheSignature(): void
    {
        $pki = TestPki::seal('5265877635');
        $signed = (new OpenSslXadesSigner($pki['certificatePem'], $pki['privateKeyPem']))
            ->sign((new AuthTokenRequestXml())->build(self::CHALLENGE, ContextIdentifier::nip('5265877635'), SubjectIdentifierType::CertificateSubject));

        $tampered = str_replace('5265877635', '1111111111', $signed);

        self::assertNotSame([], XmlDsigVerifier::verify($tampered));
    }

    public function testRefusesMismatchedKeyAndCertificate(): void
    {
        $a = TestPki::seal('5265877635');
        $b = TestPki::seal('5265877635');

        $this->expectException(SigningException::class);
        new OpenSslXadesSigner($a['certificatePem'], $b['privateKeyPem']);
    }

    public function testRefusesWeakRsaKeys(): void
    {
        $weak = TestPki::selfSigned('rsa', 1024);

        $this->expectException(SigningException::class);
        new OpenSslXadesSigner($weak['certificatePem'], $weak['privateKeyPem']);
    }

    public function testRefusesDocumentsWithADoctype(): void
    {
        $pki = TestPki::seal('5265877635');
        $signer = new OpenSslXadesSigner($pki['certificatePem'], $pki['privateKeyPem']);

        $this->expectException(SigningException::class);
        $signer->sign('<!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>');
    }

    public function testAuthRequestMatchesTheOfficialSchemaIncludingIpPolicy(): void
    {
        $xml = (new AuthTokenRequestXml())->build(
            self::CHALLENGE,
            ContextIdentifier::nip('5265877635'),
            SubjectIdentifierType::CertificateFingerprint,
            new AllowedIps(['192.168.0.1'], ['10.0.0.1-10.0.0.254'], ['192.168.1.0/24']),
        );

        self::assertStringContainsString('<SubjectIdentifierType>certificateFingerprint</SubjectIdentifierType>', $xml);
        self::assertStringContainsString('<Ip4Mask>192.168.1.0/24</Ip4Mask>', $xml);
    }

    public function testAuthRequestWithAMalformedChallengeIsRejectedBeforeSigning(): void
    {
        $this->expectException(SerializationException::class);
        (new AuthTokenRequestXml())->build('not-a-challenge', ContextIdentifier::nip('5265877635'), SubjectIdentifierType::CertificateSubject);
    }
}
