<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

/** Key algorithms KSeF accepts in certificate requests. EC is recommended by the Ministry. */
enum KeyType
{
    /** NIST P-256 (secp256r1). */
    case EcP256;

    /** RSA with exactly 2048 bits. */
    case Rsa2048;
}
