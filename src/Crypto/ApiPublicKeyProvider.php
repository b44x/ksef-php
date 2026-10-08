<?php

declare(strict_types=1);

namespace Ksef\Crypto;

use DateTimeImmutable;
use JsonException;
use Ksef\Exception\EncryptionException;
use Ksef\Http\ApiRequest;
use Ksef\Http\Transport;
use Psr\Clock\ClockInterface;

/**
 * Downloads the public keys from KSeF and caches them in memory.
 *
 * KSeF rotates keys: during a planned rotation several certificates for the same usage are
 * published. The one that is valid now and became valid most recently is selected. The cache is
 * refreshed when it is older than the TTL or when no cached key is valid any more.
 */
final class ApiPublicKeyProvider implements PublicKeyProvider
{
    /** @var list<PublicKeyCertificate>|null */
    private ?array $certificates = null;
    private ?DateTimeImmutable $fetchedAt = null;

    public function __construct(
        private readonly Transport $transport,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds = 3600,
    ) {}

    public function get(KeyUsage $usage): PublicKeyCertificate
    {
        $now = $this->clock->now();

        if ($this->certificates === null || $this->isStale($now)) {
            $this->refresh($now);
        }

        $selected = $this->select($usage, $now);
        if ($selected === null) {
            // A rotation may have happened after the cache was filled: fetch once more.
            $this->refresh($now);
            $selected = $this->select($usage, $now);
        }

        return $selected ?? throw new EncryptionException(\sprintf('KSeF does not publish a currently valid public key for "%s".', $usage->value));
    }

    private function isStale(DateTimeImmutable $now): bool
    {
        return $this->fetchedAt === null || $now->getTimestamp() - $this->fetchedAt->getTimestamp() > $this->ttlSeconds;
    }

    private function refresh(DateTimeImmutable $now): void
    {
        $response = $this->transport->send(ApiRequest::get('/security/public-key-certificates'));

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EncryptionException('The list of KSeF public keys is not valid JSON.', 0, $e);
        }
        if (!\is_array($decoded)) {
            throw new EncryptionException('The list of KSeF public keys has an unexpected structure.');
        }

        $certificates = [];
        foreach ($decoded as $entry) {
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $certificates[] = PublicKeyCertificate::fromApi($entry);
            }
        }

        $this->certificates = $certificates;
        $this->fetchedAt = $now;
    }

    private function select(KeyUsage $usage, DateTimeImmutable $now): ?PublicKeyCertificate
    {
        $best = null;
        foreach ($this->certificates ?? [] as $certificate) {
            if (!$certificate->supports($usage) || !$certificate->isValidAt($now)) {
                continue;
            }
            if ($best === null || $certificate->validFrom > $best->validFrom) {
                $best = $certificate;
            }
        }

        return $best;
    }
}
