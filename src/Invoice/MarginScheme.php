<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** The margin procedure applied to the invoice (`PMarzy`). */
enum MarginScheme: string
{
    /** Travel agency services. */
    case Travel = 'P_PMarzy_2';
    /** Second-hand goods. */
    case UsedGoods = 'P_PMarzy_3_1';
    /** Works of art. */
    case Art = 'P_PMarzy_3_2';
    /** Collectibles and antiques. */
    case Collectibles = 'P_PMarzy_3_3';
}
