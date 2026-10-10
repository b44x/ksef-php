<?php

declare(strict_types=1);

namespace B4x\Ksef\Tests\Unit\Http;

use B4x\Ksef\Auth\KsefTokenCredentials;
use B4x\Ksef\Crypto\SessionEncryption;
use B4x\Ksef\Environment;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Qr\OfflineCertificate;
use B4x\Ksef\Tests\Support\FakeHttpClient;
use B4x\Ksef\Tests\Support\Http;
use B4x\Ksef\Tests\Support\TestPki;
use B4x\Ksef\Xml\SafeXml;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretExposureTest extends TestCase
{
    public function testDumpsOfSecretHoldersAreMasked(): void
    {
        $pki = TestPki::seal('5265877635');
        $certificate = new OfflineCertificate($pki['certificatePem'], $pki['privateKeyPem']);
        $request = ApiRequest::get('/x', 'access-token-123');

        foreach ([print_r($certificate, true), print_r($request, true), print_r(new KsefTokenCredentials('ksef-token-456'), true)] as $dump) {
            self::assertStringNotContainsString('PRIVATE KEY', $dump);
            self::assertStringNotContainsString('access-token-123', $dump);
            self::assertStringNotContainsString('ksef-token-456', $dump);
        }
        self::assertSame('{"token":"***"}', json_encode(new KsefTokenCredentials('ksef-token-456')));
    }

    public function testASessionKeyCannotBeSerialized(): void
    {
        $this->expectException(LogicException::class);
        serialize(SessionEncryption::generate());
    }

    #[DataProvider('hostileBaseUrls')]
    public function testOnlyRealLoopbackHostsMayUseCleartextHttp(string $url, bool $allowed): void
    {
        $factory = Http::factory();
        if (!$allowed) {
            $this->expectException(ConfigurationException::class);
        }
        new Transport($url, new FakeHttpClient(), $factory, $factory);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hostileBaseUrls(): iterable
    {
        yield 'https' => [Environment::Test->baseUrl(), true];
        yield 'localhost' => ['http://localhost:8080/v2', true];
        yield 'loopback' => ['http://127.0.0.1/v2', true];
        yield 'localhost prefix of another host' => ['http://localhost.evil.example/v2', false];
        yield 'loopback prefix of another host' => ['http://127.0.0.1.evil.example/v2', false];
        yield 'plain http' => ['http://api.ksef.mf.gov.pl/v2', false];
    }

    public function testADoctypeInUtf16InputIsRefused(): void
    {
        $xml = mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE a [<!ENTITY e "x">]><a>&e;</a>', 'UTF-16LE', 'UTF-8');

        $this->expectException(\B4x\Ksef\Exception\SerializationException::class);
        SafeXml::load("\xFF\xFE" . $xml);
    }
}
