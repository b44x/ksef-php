<?php

declare(strict_types=1);

namespace Ksef\Signing;

use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use Ksef\Crypto\Digest;
use Ksef\Exception\SigningException;
use Ksef\Support\SystemClock;
use OpenSSLAsymmetricKey;
use phpseclib3\File\X509;
use phpseclib3\Math\BigInteger;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * XAdES-BES enveloped signer built on PHP's DOM canonicalization and OpenSSL.
 *
 * No cryptographic primitive is implemented here: hashing, canonicalization (exclusive C14N) and
 * the signature itself are delegated to ext-hash, ext-dom and ext-openssl.
 *
 * Supported keys: RSA (RSASSA-PKCS1-v1_5 with SHA-256) and EC P-256 or larger (ECDSA with SHA-256,
 * `R||S` encoded as XML Signature requires). Keys shorter than 2048 bits (RSA) are rejected.
 */
final class OpenSslXadesSigner implements XadesSigner
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';
    private const C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    private readonly OpenSSLAsymmetricKey $privateKey;
    private readonly string $certificateDer;
    private readonly string $certificatePem;
    private readonly bool $isEc;
    private readonly int $ecFieldBytes;

    public function __construct(
        string $certificatePem,
        #[SensitiveParameter]
        string $privateKeyPem,
        #[SensitiveParameter]
        ?string $passphrase = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new SigningException('The signing certificate cannot be parsed.');
        }

        $key = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($key === false) {
            throw new SigningException('The private key cannot be loaded (wrong passphrase or unsupported format).');
        }
        if (!openssl_x509_check_private_key($certificate, $key)) {
            throw new SigningException('The private key does not match the signing certificate.');
        }

        $details = openssl_pkey_get_details($key);
        $type = \is_array($details) ? ($details['type'] ?? null) : null;
        if ($type === OPENSSL_KEYTYPE_RSA) {
            $bits = $details['bits'] ?? 0;
            if (!\is_int($bits) || $bits < 2048) {
                throw new SigningException('RSA keys shorter than 2048 bits are not accepted by KSeF.');
            }
            $this->isEc = false;
            $this->ecFieldBytes = 0;
        } elseif ($type === OPENSSL_KEYTYPE_EC) {
            $bits = $details['bits'] ?? 0;
            if (!\is_int($bits) || $bits < 256) {
                throw new SigningException('EC keys smaller than 256 bits are not accepted by KSeF.');
            }
            $this->isEc = true;
            $this->ecFieldBytes = intdiv($bits + 7, 8);
        } else {
            throw new SigningException('Only RSA and EC private keys are supported for XAdES signing.');
        }

        $pem = '';
        openssl_x509_export($certificate, $pem);
        $this->certificatePem = \is_string($pem) ? $pem : throw new SigningException('The signing certificate cannot be exported.');
        $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->certificatePem), true);
        $this->certificateDer = $der !== false ? $der : throw new SigningException('The signing certificate is not valid PEM.');
        $this->privateKey = $key;
    }

    public function certificateFingerprint(): string
    {
        return Digest::sha256Hex($this->certificateDer);
    }

    public function sign(string $xml): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $document->documentElement === null) {
            throw new SigningException('The document to sign is not well-formed XML.');
        }
        if ($document->doctype !== null) {
            throw new SigningException('Documents with a DOCTYPE are refused.');
        }

        $root = $document->documentElement;
        $uuid = bin2hex(random_bytes(8));
        $signatureId = 'Signature-' . $uuid;
        $propertiesId = 'SignedProperties-' . $uuid;

        // 1. Digest of the whole document (the enveloped-signature transform removes our own signature).
        $documentDigest = base64_encode(hash('sha256', $this->canonicalize($document), true));

        // 2. Assemble ds:Signature skeleton (SignedInfo is completed after the properties are digested).
        $signature = $document->createElementNS(self::DS, 'ds:Signature');
        $signature->setAttribute('Id', $signatureId);

        $signedInfo = $this->child($document, $signature, self::DS, 'ds:SignedInfo');
        $this->child($document, $signedInfo, self::DS, 'ds:CanonicalizationMethod')->setAttribute('Algorithm', self::C14N);
        $this->child($document, $signedInfo, self::DS, 'ds:SignatureMethod')->setAttribute(
            'Algorithm',
            $this->isEc ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256' : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        );

        $documentReference = $this->child($document, $signedInfo, self::DS, 'ds:Reference');
        $documentReference->setAttribute('URI', '');
        $transforms = $this->child($document, $documentReference, self::DS, 'ds:Transforms');
        $this->child($document, $transforms, self::DS, 'ds:Transform')->setAttribute('Algorithm', self::DS . 'enveloped-signature');
        $this->child($document, $transforms, self::DS, 'ds:Transform')->setAttribute('Algorithm', self::C14N);
        $this->child($document, $documentReference, self::DS, 'ds:DigestMethod')->setAttribute('Algorithm', self::SHA256);
        $this->child($document, $documentReference, self::DS, 'ds:DigestValue', $documentDigest);

        $propertiesReference = $this->child($document, $signedInfo, self::DS, 'ds:Reference');
        $propertiesReference->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $propertiesReference->setAttribute('URI', '#' . $propertiesId);
        $propertiesTransforms = $this->child($document, $propertiesReference, self::DS, 'ds:Transforms');
        $this->child($document, $propertiesTransforms, self::DS, 'ds:Transform')->setAttribute('Algorithm', self::C14N);
        $this->child($document, $propertiesReference, self::DS, 'ds:DigestMethod')->setAttribute('Algorithm', self::SHA256);
        $propertiesDigestNode = $this->child($document, $propertiesReference, self::DS, 'ds:DigestValue');

        $signatureValue = $this->child($document, $signature, self::DS, 'ds:SignatureValue');
        $signatureValue->setAttribute('Id', 'SignatureValue-' . $uuid);

        $keyInfo = $this->child($document, $signature, self::DS, 'ds:KeyInfo');
        $x509Data = $this->child($document, $keyInfo, self::DS, 'ds:X509Data');
        $this->child($document, $x509Data, self::DS, 'ds:X509Certificate', base64_encode($this->certificateDer));

        // 3. XAdES qualifying properties (signing time and signing certificate).
        $object = $this->child($document, $signature, self::DS, 'ds:Object');
        $qualifying = $document->createElementNS(self::XADES, 'xades:QualifyingProperties');
        $qualifying->setAttribute('Target', '#' . $signatureId);
        $object->appendChild($qualifying);
        $properties = $document->createElementNS(self::XADES, 'xades:SignedProperties');
        $properties->setAttribute('Id', $propertiesId);
        $qualifying->appendChild($properties);
        $signedSignature = $this->child($document, $properties, self::XADES, 'xades:SignedSignatureProperties');
        $this->child($document, $signedSignature, self::XADES, 'xades:SigningTime', $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        $signingCertificate = $this->child($document, $signedSignature, self::XADES, 'xades:SigningCertificate');
        $cert = $this->child($document, $signingCertificate, self::XADES, 'xades:Cert');
        $certDigest = $this->child($document, $cert, self::XADES, 'xades:CertDigest');
        $this->child($document, $certDigest, self::DS, 'ds:DigestMethod')->setAttribute('Algorithm', self::SHA256);
        $this->child($document, $certDigest, self::DS, 'ds:DigestValue', Digest::sha256Base64($this->certificateDer));
        [$issuerName, $serialNumber] = $this->issuerAndSerial();
        $issuerSerial = $this->child($document, $cert, self::XADES, 'xades:IssuerSerial');
        $this->child($document, $issuerSerial, self::DS, 'ds:X509IssuerName', $issuerName);
        $this->child($document, $issuerSerial, self::DS, 'ds:X509SerialNumber', $serialNumber);

        $root->appendChild($signature);

        // 4. Digest the properties in their final context, then sign SignedInfo.
        $propertiesDigestNode->nodeValue = base64_encode(hash('sha256', $this->canonicalize($properties), true));
        $signatureValue->nodeValue = base64_encode($this->rawSignature($this->canonicalize($signedInfo)));

        $signed = $document->saveXML();
        if ($signed === false) {
            throw new SigningException('The signed document could not be serialized.');
        }

        return $signed;
    }

    private function canonicalize(DOMNode $node): string
    {
        $canonical = $node instanceof DOMDocument ? $node->C14N(true, false) : $node->C14N(true, false);

        return $canonical !== false ? $canonical : throw new SigningException('XML canonicalization failed.');
    }

    private function rawSignature(string $data): string
    {
        $produced = '';
        if (!openssl_sign($data, $produced, $this->privateKey, OPENSSL_ALGO_SHA256) || !\is_string($produced) || $produced === '') {
            throw new SigningException('OpenSSL failed to sign the document.');
        }
        $signature = $produced;

        return $this->isEc ? $this->derToFixedEcdsa($signature) : $signature;
    }

    /** Converts an ASN.1 DER ECDSA signature into the fixed-width R||S form of XML Signature. */
    private function derToFixedEcdsa(string $der): string
    {
        $offset = 2;
        if (\ord($der[1]) & 0x80) {
            $offset += \ord($der[1]) & 0x7F;
        }

        $parts = [];
        for ($i = 0; $i < 2; ++$i) {
            if (\ord($der[$offset]) !== 0x02) {
                throw new SigningException('Unexpected ECDSA signature encoding.');
            }
            $length = \ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            $parts[] = str_pad(ltrim($integer, "\x00"), $this->ecFieldBytes, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    /**
     * @return array{string, string} RFC 2253 issuer name and decimal serial number
     */
    private function issuerAndSerial(): array
    {
        $x509 = new X509();
        $loaded = $x509->loadX509($this->certificatePem);
        if (!\is_array($loaded)) {
            throw new SigningException('The signing certificate cannot be analysed.');
        }

        $issuer = $x509->getIssuerDN(X509::DN_STRING);
        $tbs = $loaded['tbsCertificate'] ?? null;
        $serial = \is_array($tbs) ? ($tbs['serialNumber'] ?? null) : null;
        if (!\is_string($issuer) || !$serial instanceof BigInteger) {
            throw new SigningException('The certificate issuer or serial number cannot be read.');
        }

        return [$issuer, $serial->toString()];
    }

    private function child(DOMDocument $document, DOMElement $parent, string $namespace, string $name, ?string $text = null): DOMElement
    {
        $element = $document->createElementNS($namespace, $name);
        if ($text !== null) {
            $element->appendChild($document->createTextNode($text));
        }
        $parent->appendChild($element);

        return $element;
    }
}
