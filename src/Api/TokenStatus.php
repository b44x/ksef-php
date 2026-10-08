<?php

declare(strict_types=1);

namespace Ksef\Api;

/** Lifecycle of a KSeF token. */
enum TokenStatus: string
{
    case Pending = 'Pending';
    case Active = 'Active';
    case Revoking = 'Revoking';
    case Revoked = 'Revoked';
    case Failed = 'Failed';

    public function isFinal(): bool
    {
        return $this !== self::Pending && $this !== self::Revoking;
    }
}
