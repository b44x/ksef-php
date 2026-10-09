<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** The role of an authorised entity on the invoice (`RolaPU`). */
enum AuthorizedEntityRole: string
{
    /** Enforcement authority (art. 106c(1) of the VAT Act). */
    case EnforcementAuthority = '1';
    /** Court bailiff (art. 106c(2) of the VAT Act). */
    case CourtBailiff = '2';
    /** Tax representative (art. 18a-18d of the VAT Act). */
    case TaxRepresentative = '3';
}
