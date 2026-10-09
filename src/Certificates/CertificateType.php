<?php

declare(strict_types=1);

namespace B4x\Ksef\Certificates;

/** Purpose of a KSeF certificate. A certificate has exactly one type. */
enum CertificateType: string
{
    /** Authenticates to the KSeF API (key usage: digital signature). */
    case Authentication = 'Authentication';

    /** Signs offline invoices (QR code II). Cannot authenticate. */
    case Offline = 'Offline';
}
