<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

enum BuyerIdentifierType
{
    case Nip;
    case EuVat;
    case Foreign;
    case None;
}
