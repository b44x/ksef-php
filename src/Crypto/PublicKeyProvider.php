<?php

declare(strict_types=1);

namespace Ksef\Crypto;

/** Supplies the currently valid KSeF public key for a purpose. Allows custom caching or pinning. */
interface PublicKeyProvider
{
    /**
     * @throws \Ksef\Exception\EncryptionException when no valid key is available
     */
    public function get(KeyUsage $usage): PublicKeyCertificate;
}
