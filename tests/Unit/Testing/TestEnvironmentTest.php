<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Testing;

use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Testing\TestEnvironment;
use B4x\Ksef\Tests\Support\FakeHttpClient;
use B4x\Ksef\Tests\Support\Http;
use PHPUnit\Framework\TestCase;

final class TestEnvironmentTest extends TestCase
{
    public function testCreatesATaxpayerWithAUsableCertificate(): void
    {
        $http = (new FakeHttpClient())->queue(Http::json(400, ['exception' => []]), Http::json(200, []));
        $factory = Http::factory();

        $taxpayer = TestEnvironment::createTaxpayer($http, $factory, $factory);

        self::assertCount(2, $http->requests, 'a collision (HTTP 400) is retried with another number');
        self::assertSame('/v2/testdata/person', $http->requests[1]->getUri()->getPath());
        self::assertStringContainsString($taxpayer->nip->value, (string) $http->requests[1]->getBody());
        self::assertIsArray(openssl_x509_parse($taxpayer->certificatePem));
        self::assertStringNotContainsString($taxpayer->privateKeyPem, print_r($taxpayer, true));
    }

    public function testRefusesEnvironmentsOtherThanTest(): void
    {
        $factory = Http::factory();

        $this->expectException(ConfigurationException::class);
        TestEnvironment::createTaxpayer(new FakeHttpClient(), $factory, $factory, Environment::Production);
    }
}
