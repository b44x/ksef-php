<?php

declare(strict_types=1);

namespace Ksef;

/**
 * Public KSeF API 2.0 environments.
 *
 * The TEST environment accepts self-signed certificates and must never receive production data.
 */
enum Environment: string
{
    case Test = 'https://api-test.ksef.mf.gov.pl/v2';
    case Demo = 'https://api-demo.ksef.mf.gov.pl/v2';
    case Production = 'https://api.ksef.mf.gov.pl/v2';

    public function baseUrl(): string
    {
        return $this->value;
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
