<?php

declare(strict_types=1);

namespace B4x\Ksef\Invoice;

/** Goods and services designation codes (`GTU_01` … `GTU_13`) used for JPK_VAT reporting. */
enum Gtu: string
{
    case Gtu01 = 'GTU_01';
    case Gtu02 = 'GTU_02';
    case Gtu03 = 'GTU_03';
    case Gtu04 = 'GTU_04';
    case Gtu05 = 'GTU_05';
    case Gtu06 = 'GTU_06';
    case Gtu07 = 'GTU_07';
    case Gtu08 = 'GTU_08';
    case Gtu09 = 'GTU_09';
    case Gtu10 = 'GTU_10';
    case Gtu11 = 'GTU_11';
    case Gtu12 = 'GTU_12';
    case Gtu13 = 'GTU_13';
}
