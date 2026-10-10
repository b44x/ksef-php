<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

use B4x\Ksef\Exception\SigningException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;
use Throwable;

/**
 * Generates a fresh key pair and a PKCS#10 request for the subject KSeF dictates.
 *
 * phpseclib builds the request because PHP's OpenSSL bindings cannot express repeated subject
 * attributes (several `givenName` values) or arbitrary OIDs reliably. Requests are signed with SHA-256.
 *
 * @internal use KsefClient::requestCertificate()
 */
final class CsrGenerator
{
    public function generate(EnrollmentData $subject, KeyType $keyType = KeyType::EcP256): GeneratedCsr
    {
        try {
            // The key algorithm OID must be plain rsaEncryption / id-ecPublicKey, and the request is
            // signed with SHA-256 (phpseclib derives the signature algorithm from the key settings).
            $generated = $keyType === KeyType::EcP256 ? EC::createKey('secp256r1') : RSA::createKey(2048)->withPadding(RSA::SIGNATURE_PKCS1 | RSA::ENCRYPTION_PKCS1);
            // phpseclib's fluent setters are untyped; the result is narrowed explicitly.
            $private = $generated instanceof EC\PrivateKey || $generated instanceof RSA\PrivateKey ? $generated->withHash('sha256') : null;
            if (!$private instanceof PrivateKey) {
                throw new SigningException('Cannot configure the signing key.');
            }

            $rdnSequence = [];
            foreach ($subject->toAttributes() as [$oid, $value, $type]) {
                $rdnSequence[] = [['type' => $oid, 'value' => [$type => $value]]];
            }

            $x509 = new X509();
            $x509->setPrivateKey($private);
            $x509->setDN(['rdnSequence' => $rdnSequence]);
            $signed = $x509->signCSR();
            $pem = \is_array($signed) ? $x509->saveCSR($signed) : false;
            if (!\is_string($pem)) {
                throw new SigningException('Cannot create the certificate request.');
            }

            $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), true);
            if ($der === false || $der === '') {
                throw new SigningException('The certificate request is not valid PEM.');
            }

            return new GeneratedCsr(base64_encode($der), $this->export($private));
        } catch (SigningException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SigningException('Certificate request generation failed.', 0, $e);
        }
    }

    private function export(PrivateKey $key): string
    {
        $pem = $key->toString('PKCS8');

        return \is_string($pem) ? $pem : throw new SigningException('Cannot export the private key.');
    }
}
