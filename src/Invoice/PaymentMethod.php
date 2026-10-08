<?php

declare(strict_types=1);

namespace Ksef\Invoice;

/** `FormaPlatnosci`. Case values are the FA(3) codes. */
enum PaymentMethod: int
{
    case Cash = 1;
    case Card = 2;
    case Voucher = 3;
    case Cheque = 4;
    case Credit = 5;
    case BankTransfer = 6;
    case Mobile = 7;
}
