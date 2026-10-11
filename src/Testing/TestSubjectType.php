<?php

declare(strict_types=1);

namespace B4x\Ksef\Testing;

/** The kinds of entity with subordinate units that can be created in the TEST data API. */
enum TestSubjectType: string
{
    case EnforcementAuthority = 'EnforcementAuthority';
    case VatGroup = 'VatGroup';
    case LocalGovernmentUnit = 'JST';
}
