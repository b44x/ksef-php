<?php

declare(strict_types=1);

namespace B4x\Ksef\Doctor;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
