<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

/** State of a certificate in KSeF's register. */
enum CertificateStatus: string
{
    case Active = 'Active';
    case Blocked = 'Blocked';
    case Expired = 'Expired';
    case Revoked = 'Revoked';
}
