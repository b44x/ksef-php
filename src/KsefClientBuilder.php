<?php

declare(strict_types=1);

namespace B4x\Ksef;

use B4x\Ksef\Api\InvoiceApi;
use B4x\Ksef\Api\SessionApi;
use B4x\Ksef\Api\TokenApi;
use B4x\Ksef\Auth\AccessTokenProvider;
use B4x\Ksef\Auth\AllowedIps;
use B4x\Ksef\Auth\AuthApi;
use B4x\Ksef\Auth\Authenticator;
use B4x\Ksef\Auth\ContextIdentifier;
use B4x\Ksef\Auth\Credentials;
use B4x\Ksef\Batch\BatchPackager;
use B4x\Ksef\Batch\BatchSender;
use B4x\Ksef\Certificates\CertificateApi;
use B4x\Ksef\Crypto\ApiPublicKeyProvider;
use B4x\Ksef\Crypto\KsefTokenEncryptor;
use B4x\Ksef\Crypto\PublicKeyProvider;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Http\AuthorizedClient;
use B4x\Ksef\Http\NativeSleeper;
use B4x\Ksef\Http\RetryPolicy;
use B4x\Ksef\Http\Sleeper;
use B4x\Ksef\Http\Transport;
use B4x\Ksef\Polling\Poller;
use B4x\Ksef\Polling\PollingPolicy;
use B4x\Ksef\Session\InvoiceFactory;
use B4x\Ksef\Session\SubmissionRecoveryPolicy;
use B4x\Ksef\Support\SystemClock;
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
    private SubmissionRecoveryPolicy $recovery;
    private string $userAgent = 'ksef-php';

    public function __construct()
    {
        $this->retryPolicy = new RetryPolicy();
        $this->pollingPolicy = new PollingPolicy();
        $this->recovery = new SubmissionRecoveryPolicy();
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

    /**
     * Controls the automatic reconciliation after an ambiguous submission failure.
     * `SubmissionRecoveryPolicy::disabled()` surfaces every such failure immediately.
     */
    public function submissionRecovery(SubmissionRecoveryPolicy $policy): self
    {
        $this->recovery = $policy;

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

        $sessionApi = new SessionApi($authorized);

        return new KsefClient(
            $sessionApi,
            new InvoiceApi($authorized),
            new TokenApi($authorized),
            $keys,
            new InvoiceFactory($clock),
            $poller,
            $this->pollingPolicy,
            $clock,
            $logger,
            $this->recovery,
            $sleeper,
            new BatchSender($sessionApi, $transport, $keys, new BatchPackager(), $logger),
            new CertificateApi($authorized),
        );
    }
}
