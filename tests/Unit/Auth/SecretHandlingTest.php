<?php

declare(strict_types=1);

namespace Ksef\Tests\Unit\Auth;

use DateTimeImmutable;
use Ksef\Api\GeneratedToken;
use Ksef\Auth\AllowedIps;
use Ksef\Auth\ContextIdentifier;
use Ksef\Auth\KsefTokenCredentials;
use Ksef\Auth\TokenInfo;
use Ksef\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretHandlingTest extends TestCase
{
    public function testSecretsAreHiddenFromDumps(): void
    {
        $secret = 'super-secret-value';
        $objects = [
            new KsefTokenCredentials($secret),
            new TokenInfo($secret, new DateTimeImmutable('+1 hour')),
            new GeneratedToken('ref', $secret),
        ];

        foreach ($objects as $object) {
            ob_start();
            var_dump($object);
            $dump = (string) ob_get_clean();
            self::assertStringNotContainsString($secret, $dump, $object::class);
            self::assertStringNotContainsString($secret, print_r($object, true) === '' ? '' : (string) json_encode($object->__debugInfo()), $object::class);
        }
    }

    public function testEmptyTokensAreRefused(): void
    {
        $this->expectException(ValidationException::class);
        new KsefTokenCredentials('  ');
    }

    #[DataProvider('invalidContexts')]
    public function testContextIdentifiersAreValidated(callable $factory): void
    {
        $this->expectException(ValidationException::class);
        $factory();
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidContexts(): iterable
    {
        yield 'short nip' => [static fn() => ContextIdentifier::nip('123')];
        yield 'nip with letters' => [static fn() => ContextIdentifier::nip('52658776AB')];
        yield 'internal id without suffix' => [static fn() => ContextIdentifier::internalId('5265877635')];
        yield 'peppol lower case' => [static fn() => ContextIdentifier::peppolId('pxx123456')];
        yield 'bad ip' => [static fn() => new AllowedIps(['999.1.1.1'])];
        yield 'bad mask' => [static fn() => new AllowedIps([], [], ['10.0.0.0/33'])];
    }

    public function testValidContextsRoundTripToTheApiShape(): void
    {
        self::assertSame(['type' => 'Nip', 'value' => '5265877635'], ContextIdentifier::nip('5265877635')->toArray());
        self::assertSame(['type' => 'InternalId', 'value' => '5265877635-12345'], ContextIdentifier::internalId('5265877635-12345')->toArray());
        self::assertSame('NipVatUe', ContextIdentifier::nipVatUe('5265877635-DE123456789')->type->value);
        self::assertSame(['ip4Addresses' => ['10.0.0.1'], 'ip4Ranges' => [], 'ip4Masks' => ['10.0.0.0/8']], (new AllowedIps(['10.0.0.1'], [], ['10.0.0.0/8']))->toArray());
    }
}
