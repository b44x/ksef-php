<?php

declare(strict_types=1);

namespace B4x\Ksef;

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

    /** Host of the public verification pages that QR codes point to. */
    public function qrBaseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://qr-test.ksef.mf.gov.pl',
            self::Demo => 'https://qr-demo.ksef.mf.gov.pl',
            self::Production => 'https://qr.ksef.mf.gov.pl',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
