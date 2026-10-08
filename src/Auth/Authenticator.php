<?php

declare(strict_types=1);

namespace Ksef\Auth;

use Ksef\Crypto\KsefTokenEncryptor;
use Ksef\Exception\AuthenticationException;
use Ksef\Exception\ConfigurationException;
use Ksef\Http\ApiError;
use Ksef\Polling\Poller;
use Ksef\Polling\PollingPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the complete KSeF authentication flow:
 * challenge, proof of identity (XAdES signature or encrypted KSeF token), status polling and
 * exchange of the temporary token for access and refresh tokens.
 */
final class Authenticator
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AuthApi $api,
        private readonly KsefTokenEncryptor $tokenEncryptor,
        private readonly Poller $poller,
        private readonly PollingPolicy $polling = new PollingPolicy(),
        private readonly AuthTokenRequestXml $requestXml = new AuthTokenRequestXml(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws AuthenticationException when KSeF refuses the identity or the operation fails
     * @throws \Ksef\Exception\PollingTimeoutException when KSeF does not finish verifying in time
     */
    public function authenticate(ContextIdentifier $context, Credentials $credentials, ?AllowedIps $allowedIps = null): AuthTokens
    {
        $challenge = $this->api->challenge();

        $operation = match (true) {
            $credentials instanceof CertificateCredentials => $this->api->submitSignedRequest(
                $credentials->signer->sign($this->requestXml->build($challenge->challenge, $context, $credentials->identifiedBy, $allowedIps)),
            ),
            $credentials instanceof KsefTokenCredentials => $this->submitToken($challenge, $context, $credentials, $allowedIps),
            default => throw new ConfigurationException(\sprintf('Unsupported credentials type "%s".', $credentials::class)),
        };

        $this->logger->info('KSeF authentication started.', ['reference' => $operation->referenceNumber]);

        $status = $this->poller->poll(
            fn(): AuthStatus => $this->api->status($operation),
            static fn(AuthStatus $status): bool => !$status->isInProgress(),
            $this->polling,
            'KSeF to finish verifying the credentials',
        );

        if (!$status->isSuccessful()) {
            throw new AuthenticationException(
                \sprintf('KSeF authentication failed (%d): %s %s', $status->code, $status->description, implode('; ', $status->details)),
                401,
                [new ApiError($status->code, $status->description, $status->details)],
                $operation->referenceNumber,
            );
        }

        $tokens = $this->api->redeem($operation);
        $this->logger->info('KSeF authentication succeeded.', ['reference' => $operation->referenceNumber, 'method' => $status->method]);

        return $tokens;
    }

    private function submitToken(AuthChallenge $challenge, ContextIdentifier $context, KsefTokenCredentials $credentials, ?AllowedIps $allowedIps): AuthOperation
    {
        $encrypted = $this->tokenEncryptor->encrypt($credentials->token, $challenge->timestampMs);

        return $this->api->submitKsefToken($challenge->challenge, $context, $encrypted['encryptedToken'], $encrypted['publicKeyId'], $allowedIps);
    }
}
