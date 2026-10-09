<?php

declare(strict_types=1);

namespace B4x\Ksef\Crypto;

/** Supplies the currently valid KSeF public key for a purpose. Allows custom caching or pinning. */
interface PublicKeyProvider
{
    /**
     * @throws \B4x\Ksef\Exception\EncryptionException when no valid key is available
     */
    public function get(KeyUsage $usage): PublicKeyCertificate;
}
