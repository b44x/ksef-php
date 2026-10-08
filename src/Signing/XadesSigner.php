<?php

declare(strict_types=1);

namespace Ksef\Signing;

/**
 * Produces an enveloped XAdES signature for an XML document.
 *
 * Implement this interface to sign with a key that never leaves an HSM, smart card or remote
 * signing service. {@see OpenSslXadesSigner} is the bundled implementation.
 */
interface XadesSigner
{
    /**
     * @param string $xml the unsigned document (UTF-8)
     *
     * @return string the same document with an enveloped ds:Signature as the last child of the root
     *
     * @throws \Ksef\Exception\SigningException
     */
    public function sign(string $xml): string;

    /** SHA-256 fingerprint (lower-case hex) of the signing certificate, for `certificateFingerprint` identification. */
    public function certificateFingerprint(): string;
}
