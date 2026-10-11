<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

use B4x\Ksef\Crypto\Digest;
use B4x\Ksef\Xml\XmlSignatureVerifier;

/** An Official Confirmation of Receipt (UPO): an XML document signed by the Ministry of Finance. */
final readonly class Upo
{
    public function __construct(
        public string $xml,
        public string $hash,
    ) {}

    public function verifyHash(): bool
    {
        return hash_equals($this->hash, Digest::sha256Base64($this->xml));
    }

    /**
     * Checks the XML signature of the UPO: that the document was not changed and which certificate signed it.
     *
     * Without a certificate to trust, a valid result proves integrity only. To prove that the Ministry of Finance
     * signed it, pass its certificate (or the certificate of the authority that issued the signer's) as PEM; then
     * {@see SignatureCheck::$trusted} is true when the signer is that certificate or was issued by it. Take the
     * certificate from the Ministry's official publication, not from the UPO itself.
     */
    public function verifySignature(?string $trustedCertificatePem = null): SignatureCheck
    {
        return (new XmlSignatureVerifier())->verify($this->xml, $trustedCertificatePem);
    }
}
