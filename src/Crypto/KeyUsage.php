<?php

declare(strict_types=1);

namespace Ksef\Crypto;

/** Purpose of a Ministry of Finance public key published at /security/public-key-certificates. */
enum KeyUsage: string
{
    /** Encrypts the KSeF token (`token|timestamp`) during token authentication. */
    case KsefTokenEncryption = 'KsefTokenEncryption';

    /** Encrypts the AES key that protects invoices and batch parts. */
    case SymmetricKeyEncryption = 'SymmetricKeyEncryption';
}
