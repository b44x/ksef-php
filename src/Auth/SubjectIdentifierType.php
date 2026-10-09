<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

/** How KSeF identifies the signer of an AuthTokenRequest. */
enum SubjectIdentifierType: string
{
    /** NIP or PESEL taken from the certificate subject. */
    case CertificateSubject = 'certificateSubject';

    /** SHA-256 fingerprint of the certificate; permissions must have been granted to that fingerprint. */
    case CertificateFingerprint = 'certificateFingerprint';
}
