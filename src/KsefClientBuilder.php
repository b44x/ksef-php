<?php

declare(strict_types=1);

namespace Ksef;

use Ksef\Api\InvoiceApi;
use Ksef\Api\SessionApi;
use Ksef\Api\TokenApi;
use Ksef\Auth\AccessTokenProvider;
use Ksef\Auth\AllowedIps;
use Ksef\Auth\AuthApi;
use Ksef\Auth\Authenticator;
use Ksef\Auth\ContextIdentifier;
use Ksef\Auth\Credentials;
use Ksef\Crypto\ApiPublicKeyProvider;
use Ksef\Crypto\KsefTokenEncryptor;
use Ksef\Crypto\PublicKeyProvider;
use Ksef\Exception\ConfigurationException;
use Ksef\Http\AuthorizedClient;
use Ksef\Http\NativeSleeper;
use Ksef\Http\RetryPolicy;
use Ksef\Http\Sleeper;
use Ksef\Http\Transport;
use Ksef\Polling\Poller;
use Ksef\Polling\PollingPolicy;
use Ksef\Session\InvoiceFactory;
use Ksef\Support\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires a {@see KsefClient}. There is deliberately no default environment: production must be chosen explicitly.
 */
final class KsefClientBuilder
{
    private ?string $baseUrl = null;
    private ?ClientInterface $httpClient = null;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;
    private ?ContextIdentifier $context = null;
    private ?Credentials $credentials = null;
    private ?LoggerInterface $logger = null;
    private ?ClockInterface $clock = null;
    private ?Sleeper $sleeper = null;
    private RetryPolicy $retryPolicy;
    private PollingPolicy $pollingPolicy;
    private PollingPolicy $authenticationPolling;
    private ?AllowedIps $allowedIps = null;
    private ?PublicKeyProvider $keyProvider = null;
    private string $userAgent = 'ksef-php';

    public function __construct()
    {
        $this->retryPolicy = new RetryPolicy();
        $this->pollingPolicy = new PollingPolicy();
        // Qualified certificates may need a while for OCSP/CRL checks on PRE/PROD.
        $this->authenticationPolling = new PollingPolicy(1.0, 5.0, 1.5, 180.0);
    }

    public function environment(Environment $environment): self
    {
        $this->baseUrl = $environment->baseUrl();

        return $this;
    }

    /** Custom API root (for example a local mock server), including the `/v2` suffix. */
    public function baseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        return $this;
    }

    /**
     * Any PSR-18 client with PSR-17 factories. Configure timeouts and keep TLS verification enabled on the client.
     */
    public function httpClient(ClientInterface $client, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory): self
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;

        return $this;
    }

    public function context(ContextIdentifier $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function credentials(Credentials $credentials): self
    {
        $this->credentials = $credentials;

        return $this;
    }

    /** Optional PSR-3 logger. Secrets and document contents are never logged. */
    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function clock(ClockInterface $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    public function sleeper(Sleeper $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    public function retryPolicy(RetryPolicy $policy): self
    {
        $this->retryPolicy = $policy;

        return $this;
    }

    /** Default policy for waiting on invoices and sessions. */
    public function polling(PollingPolicy $policy): self
    {
        $this->pollingPolicy = $policy;

        return $this;
    }

    /** Policy for waiting until KSeF has verified the credentials. */
    public function authenticationPolling(PollingPolicy $policy): self
    {
        $this->authenticationPolling = $policy;

        return $this;
    }

    /** Restricts the issued access token to the given client IP addresses. */
    public function allowedIps(AllowedIps $allowedIps): self
    {
        $this->allowedIps = $allowedIps;

        return $this;
    }

    /** Replaces the default public key lookup, for example to pin certificates. */
    public function publicKeyProvider(PublicKeyProvider $provider): self
    {
        $this->keyProvider = $provider;

        return $this;
    }

    public function userAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * @throws ConfigurationException listing everything that is missing
     */
    public function build(): KsefClient
    {
        $missing = [];
        if ($this->baseUrl === null) {
            $missing[] = 'environment() or baseUrl()';
        }
        if ($this->httpClient === null || $this->requestFactory === null || $this->streamFactory === null) {
            $missing[] = 'httpClient()';
        }
        if ($this->context === null) {
            $missing[] = 'context()';
        }
        if ($this->credentials === null) {
            $missing[] = 'credentials()';
        }
        if ($this->baseUrl === null || $this->httpClient === null || $this->requestFactory === null || $this->streamFactory === null || $this->context === null || $this->credentials === null) {
            throw new ConfigurationException('The KSeF client is not fully configured. Missing: ' . implode(', ', $missing) . '.');
        }

        $logger = $this->logger ?? new NullLogger();
        $clock = $this->clock ?? new SystemClock();
        $sleeper = $this->sleeper ?? new NativeSleeper();
        $poller = new Poller($clock, $sleeper);

        $transport = new Transport($this->baseUrl, $this->httpClient, $this->requestFactory, $this->streamFactory, $this->retryPolicy, $sleeper, $logger, $this->userAgent);
        $keys = $this->keyProvider ?? new ApiPublicKeyProvider($transport, $clock);
        $authApi = new AuthApi($transport);
        $authenticator = new Authenticator($authApi, new KsefTokenEncryptor($keys), $poller, $this->authenticationPolling, logger: $logger);
        $tokens = new AccessTokenProvider($authenticator, $authApi, $this->context, $this->credentials, $clock, $this->allowedIps, logger: $logger);
        $authorized = new AuthorizedClient($transport, $tokens);

        return new KsefClient(
            new SessionApi($authorized),
            new InvoiceApi($authorized),
            new TokenApi($authorized),
            $keys,
            new InvoiceFactory($clock),
            $poller,
            $this->pollingPolicy,
            $clock,
            $logger,
        );
    }
}
