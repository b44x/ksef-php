<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Testing;

use B4x\Ksef\Auth\ContextIdentifierType;
use B4x\Ksef\Testing\TestEnvironment;
use PHPUnit\Framework\TestCase;

final class TestPeppolProviderTest extends TestCase
{
    public function testAProviderHasAPeppolIdAndACertificateNamedAfterIt(): void
    {
        $provider = TestEnvironment::createPeppolProvider();

        self::assertMatchesRegularExpression('/^P[A-Z]{2}[0-9]{6}$/', $provider->id);
        self::assertSame(ContextIdentifierType::PeppolId, $provider->context()->type);
        $parsed = openssl_x509_parse($provider->certificatePem);
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['subject']);
        self::assertSame($provider->id, $parsed['subject']['CN'] ?? null);
        self::assertStringNotContainsString($provider->privateKeyPem, print_r($provider, true));
    }
}
