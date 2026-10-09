<?php

declare(strict_types=1);

namespace B4x\Ksef\Permissions;

enum AuthorizationDirection: string
{
    /** Authorisations the current context has given to others. */
    case Granted = 'Granted';
    /** Authorisations the current context has received. */
    case Received = 'Received';
}
