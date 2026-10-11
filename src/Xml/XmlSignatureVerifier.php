<?php

declare(strict_types=1);

namespace B4x\Ksef\Xml;

use B4x\Ksef\Status\SignatureCheck;
use B4x\Ksef\Support\EcdsaSignature;
use DateTimeImmutable;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * Verifies an enveloped XML-DSig / XAdES signature (RSA or ECDSA with SHA-256) against the certificate embedded in it.
 *
 * Only what KSeF documents use is accepted: enveloped-signature and canonicalisation transforms, SHA-256 digests,
 * and a reference to the whole document (a signature that does not cover the document proves nothing).
 *
 * @internal use Upo::verifySignature()
 */
final class XmlSignatureVerifier
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const C14N = [
        'http://www.w3.org/TR/2001/REC-xml-c14n-20010315' => [false, false],
        'http://www.w3.org/TR/2001/REC-xml-c14n-20010315#WithComments' => [false, true],
        'http://www.w3.org/2001/10/xml-exc-c14n#' => [true, false],
        'http://www.w3.org/2001/10/xml-exc-c14n#WithComments' => [true, true],
    ];

    private const SIGNATURE_ALGORITHMS = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => false,
        'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256' => true,
    ];

    /**
     * @param string|null $trustedCertificatePem a certificate that is trusted as it is, or one that issued the signer's
     */
    public function verify(string $xml, ?string $trustedCertificatePem = null): SignatureCheck
    {
        try {
            $document = SafeXml::load($xml);
        } catch (Throwable) {
            return new SignatureCheck(false, ['The document is not well-formed XML.']);
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::DS);
        $root = $document->documentElement;
        $signatures = $xpath->query('/*/ds:Signature');
        if ($root === null || $signatures === false || $signatures->length !== 1 || !$signatures->item(0) instanceof DOMElement) {
            return new SignatureCheck(false, ['The document must contain exactly one enveloped ds:Signature.']);
        }
        $signature = $signatures->item(0);

        $problems = [];
        $signedInfo = $this->one($xpath, 'ds:SignedInfo', $signature);
        $certificateBase64 = preg_replace('/\s+/', '', $this->text($xpath, 'string(ds:KeyInfo/ds:X509Data/ds:X509Certificate)', $signature)) ?? '';
        $signatureValue = base64_decode($this->text($xpath, 'string(ds:SignatureValue)', $signature), true);
        $method = $this->text($xpath, 'string(ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $signature);
        $canonicalization = $this->text($xpath, 'string(ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm)', $signature);

        if (!$signedInfo instanceof DOMElement || $signatureValue === false || $certificateBase64 === '') {
            return new SignatureCheck(false, ['The signature is incomplete (SignedInfo, SignatureValue or the certificate is missing).']);
        }
        if (!isset(self::SIGNATURE_ALGORITHMS[$method])) {
            return new SignatureCheck(false, [\sprintf('Unsupported signature algorithm "%s".', $method)]);
        }
        if (!isset(self::C14N[$canonicalization])) {
            return new SignatureCheck(false, [\sprintf('Unsupported canonicalisation "%s".', $canonicalization)]);
        }

        $certificatePem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificateBase64, 64, "\n") . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($certificatePem);
        $info = openssl_x509_parse($certificatePem);
        if ($publicKey === false || $info === false) {
            return new SignatureCheck(false, ['The embedded certificate cannot be read.']);
        }

        // 1. Every reference digest, and at least one reference to the whole document.
        $coversDocument = false;
        $references = $xpath->query('ds:SignedInfo/ds:Reference', $signature);
        if ($references === false || $references->length === 0) {
            $problems[] = 'The signature has no references.';
        } else {
            foreach ($references as $reference) {
                if (!$reference instanceof DOMElement) {
                    continue;
                }
                $uri = $reference->getAttribute('URI');
                if ($uri === '') {
                    $coversDocument = true;
                }
                $problem = $this->checkReference($xml, $xpath, $reference, $uri);
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }
        if (!$coversDocument) {
            $problems[] = 'The signature does not cover the whole document.';
        }

        // 2. The signature itself, over the canonical SignedInfo.
        [$exclusive, $comments] = self::C14N[$canonicalization];
        $canonicalSignedInfo = $signedInfo->C14N($exclusive, $comments);
        $value = self::SIGNATURE_ALGORITHMS[$method] ? EcdsaSignature::fixedToDer($signatureValue) : $signatureValue;
        if (openssl_verify((string) $canonicalSignedInfo, $value, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            $problems[] = 'The signature value does not verify.';
        }

        return new SignatureCheck(
            $problems === [],
            $problems,
            $this->isTrusted($certificatePem, $trustedCertificatePem),
            $this->name($info['subject'] ?? null),
            $this->name($info['issuer'] ?? null),
            \is_string($info['serialNumberHex'] ?? null) ? $info['serialNumberHex'] : null,
            hash('sha256', (string) base64_decode($certificateBase64, true)),
            $this->time($info['validFrom_time_t'] ?? null),
            $this->time($info['validTo_time_t'] ?? null),
        );
    }

    private function checkReference(string $xml, DOMXPath $xpath, DOMElement $reference, string $uri): ?string
    {
        $digestMethod = $this->text($xpath, 'string(ds:DigestMethod/@Algorithm)', $reference);
        if ($digestMethod !== 'http://www.w3.org/2001/04/xmlenc#sha256') {
            return \sprintf('Unsupported digest algorithm "%s" in reference "%s".', $digestMethod, $uri);
        }

        $enveloped = false;
        $c14n = self::C14N['http://www.w3.org/TR/2001/REC-xml-c14n-20010315'];
        $transforms = $xpath->query('ds:Transforms/ds:Transform', $reference);
        foreach ($transforms === false ? [] : iterator_to_array($transforms) as $transform) {
            $algorithm = $transform instanceof DOMElement ? $transform->getAttribute('Algorithm') : '';
            if ($algorithm === 'http://www.w3.org/2000/09/xmldsig#enveloped-signature') {
                $enveloped = true;
            } elseif (isset(self::C14N[$algorithm])) {
                $c14n = self::C14N[$algorithm];
            } else {
                return \sprintf('Unsupported transform "%s" in reference "%s".', $algorithm, $uri);
            }
        }

        if ($uri === '') {
            // A fresh parse: copying nodes between documents would hoist namespace declarations and change the bytes.
            $copy = SafeXml::load($xml);
            if ($enveloped) {
                $copyXpath = new DOMXPath($copy);
                $copyXpath->registerNamespace('ds', self::DS);
                $found = $copyXpath->query('/*/ds:Signature');
                $node = $found === false ? null : $found->item(0);
                if ($node instanceof DOMNode && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
            $canonical = $copy->C14N($c14n[0], $c14n[1]);
        } elseif (str_starts_with($uri, '#')) {
            $id = substr($uri, 1);
            if (preg_match('/^[A-Za-z0-9_.:-]+$/D', $id) !== 1) {
                return 'A reference points to an invalid identifier.';
            }
            $targets = $xpath->query('//*[@Id="' . $id . '"]');
            if ($targets === false || $targets->length !== 1 || !$targets->item(0) instanceof DOMElement) {
                return \sprintf('The signed element "%s" is missing or ambiguous.', $uri);
            }
            $canonical = $targets->item(0)->C14N($c14n[0], $c14n[1]);
        } else {
            return \sprintf('External reference "%s" is not supported.', $uri);
        }

        $expected = $this->text($xpath, 'string(ds:DigestValue)', $reference);
        $actual = base64_encode(hash('sha256', (string) $canonical, true));

        return hash_equals($expected, $actual) ? null : \sprintf('The digest of reference "%s" does not match (the document was changed).', $uri === '' ? 'whole document' : $uri);
    }

    private function isTrusted(string $signerPem, ?string $trustedPem): bool
    {
        if ($trustedPem === null) {
            return false;
        }
        $trusted = openssl_x509_read($trustedPem);
        $signer = openssl_x509_read($signerPem);
        if ($trusted === false || $signer === false) {
            return false;
        }
        if (openssl_x509_fingerprint($signer, 'sha256') === openssl_x509_fingerprint($trusted, 'sha256')) {
            return true;
        }
        $trustedKey = openssl_pkey_get_public($trusted);

        return $trustedKey !== false && openssl_x509_verify($signer, $trustedKey) === 1;
    }

    private function name(mixed $parts): string
    {
        $out = [];
        foreach (\is_array($parts) ? $parts : [] as $key => $value) {
            $values = \is_array($value) ? $value : [$value];
            $out[] = $key . '=' . implode('+', array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $values));
        }

        return implode(', ', $out);
    }

    private function time(mixed $timestamp): ?DateTimeImmutable
    {
        return \is_int($timestamp) ? (new DateTimeImmutable())->setTimestamp($timestamp) : null;
    }

    private function one(DOMXPath $xpath, string $expression, DOMElement $context): ?DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath $xpath, string $expression, DOMElement $context): string
    {
        $value = $xpath->evaluate($expression, $context);

        return \is_string($value) ? trim($value) : '';
    }
}
