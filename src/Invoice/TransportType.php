<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Kind of transport used for the delivery (`RodzajTransportu`). */
enum TransportType: int
{
    case Sea = 1;
    case Rail = 2;
    case Road = 3;
    case Air = 4;
    case Post = 5;
    case Pipeline = 7;
    case InlandWaterway = 8;
}
