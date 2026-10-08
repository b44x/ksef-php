<?php

declare(strict_types=1);

namespace Ksef\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Minimal, independent XML-DSig verifier for tests: checks both reference digests and the
 * SignatureValue against the certificate embedded in KeyInfo.
 */
final class XmlDsigVerifier
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * @return list<string> problems, empty when the signature is valid
     */
    public static function verify(string $signedXml): array
    {
        $document = new DOMDocument();
        $document->loadXML($signedXml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::DS);
        $xpath->registerNamespace('xades', self::XADES);

        $problems = [];
        $signature = self::first($xpath, '/*/ds:Signature');
        if (!$signature instanceof DOMElement) {
            return ['No enveloped ds:Signature found.'];
        }

        // Reference 1: whole document without the signature (enveloped-signature + exc-c14n).
        $copy = new DOMDocument();
        $copy->loadXML($signedXml);
        $copyXpath = new DOMXPath($copy);
        $copyXpath->registerNamespace('ds', self::DS);
        $copySignature = self::first($copyXpath, '/*/ds:Signature');
        if ($copySignature instanceof DOMElement && $copySignature->parentNode !== null) {
            $copySignature->parentNode->removeChild($copySignature);
        }
        $expectedDocumentDigest = base64_encode(hash('sha256', (string) $copy->C14N(true, false), true));
        $actualDocumentDigest = self::text($xpath, 'string(ds:SignedInfo/ds:Reference[@URI=""]/ds:DigestValue)', $signature);
        if ($expectedDocumentDigest !== $actualDocumentDigest) {
            $problems[] = 'Document digest mismatch.';
        }

        // Reference 2: SignedProperties.
        $uri = self::text($xpath, 'string(ds:SignedInfo/ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]/@URI)', $signature);
        $properties = self::first($xpath, '//xades:SignedProperties[@Id="' . ltrim($uri, '#') . '"]');
        if (!$properties instanceof DOMElement) {
            $problems[] = 'SignedProperties not found for the reference URI.';
        } else {
            $expected = base64_encode(hash('sha256', (string) $properties->C14N(true, false), true));
            $actual = self::text($xpath, 'string(ds:SignedInfo/ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]/ds:DigestValue)', $signature);
            if ($expected !== $actual) {
                $problems[] = 'SignedProperties digest mismatch.';
            }
        }

        // SignatureValue over canonical SignedInfo.
        $signedInfo = self::first($xpath, 'ds:SignedInfo', $signature);
        $certificateB64 = self::text($xpath, 'string(ds:KeyInfo/ds:X509Data/ds:X509Certificate)', $signature);
        $method = self::text($xpath, 'string(ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $signature);
        $value = base64_decode(self::text($xpath, 'string(ds:SignatureValue)', $signature), true);
        if (!$signedInfo instanceof DOMElement || $value === false) {
            return [...$problems, 'Malformed SignedInfo or SignatureValue.'];
        }

        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificateB64, 64, "\n") . "-----END CERTIFICATE-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return [...$problems, 'Embedded certificate cannot be read.'];
        }

        if (str_contains((string) $method, 'ecdsa')) {
            $half = intdiv(\strlen($value), 2);
            $value = self::fixedToDer(substr($value, 0, $half), substr($value, $half));
        }

        $result = openssl_verify((string) $signedInfo->C14N(true, false), $value, $key, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            $problems[] = 'SignatureValue does not verify.';
        }

        return $problems;
    }

    private static function first(DOMXPath $xpath, string $expression, ?DOMElement $context = null): ?DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMElement ? $node : null;
    }

    private static function text(DOMXPath $xpath, string $expression, ?DOMElement $context = null): string
    {
        $value = $xpath->evaluate($expression, $context);

        return \is_string($value) ? $value : '';
    }

    private static function fixedToDer(string $r, string $s): string
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
