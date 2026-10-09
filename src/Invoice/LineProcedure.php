<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/**
 * Special VAT procedure marker of a line (`Procedura`). The documentation of each case names the paragraph of the
 * regulation on tax returns and VAT records (§ 10(4)) that defines the procedure.
 */
enum LineProcedure: string
{
    /** § 10(4)(2a). */
    case WstoEe = 'WSTO_EE';
    /** § 10(4)(2b). */
    case Ied = 'IED';
    /** § 10(4)(5): triangular transaction, second taxpayer. */
    case TtD = 'TT_D';
    /** § 10(4)(8). */
    case I42 = 'I_42';
    /** § 10(4)(9). */
    case I63 = 'I_63';
    /** § 10(4)(10). */
    case BSpv = 'B_SPV';
    /** § 10(4)(11). */
    case BSpvDelivery = 'B_SPV_DOSTAWA';
    /** § 10(4)(12). */
    case BMpvCommission = 'B_MPV_PROWIZJA';
}
