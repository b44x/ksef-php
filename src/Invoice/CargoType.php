<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Kind of packaging of the transported goods (`OpisLadunku`). */
enum CargoType: int
{
    case Jerrycan = 1;
    case Barrel = 2;
    case GasCylinder = 3;
    case Carton = 4;
    case Canister = 5;
    case Cage = 6;
    case Container = 7;
    case Basket = 8;
    case Hamper = 9;
    case Multipack = 10;
    case Parcel = 11;
    case Package = 12;
    case Pallet = 13;
    case Receptacle = 14;
    case BulkSolidsReceptacle = 15;
    case BulkLiquidsReceptacle = 16;
    case Box = 17;
    case Can = 18;
    case Crate = 19;
    case Sack = 20;
}
