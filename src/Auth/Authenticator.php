<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Crypto\KsefTokenEncryptor;
use B4x\Ksef\Exception\AuthenticationException;
use B4x\Ksef\Exception\ConfigurationException;
use B4x\Ksef\Exception\ManualSignatureRequiredException;
use B4x\Ksef\Http\ApiError;
use B4x\Ksef\Polling\Poller;
use B4x\Ksef\Polling\PollingPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the complete KSeF authentication flow:
 * challenge, proof of identity (XAdES signature or encrypted KSeF token), status polling and
 * exchange of the temporary token for access and refresh tokens.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
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
     * @throws \B4x\Ksef\Exception\PollingTimeoutException when KSeF does not finish verifying in time
     */
    public function authenticate(ContextIdentifier $context, Credentials $credentials, ?AllowedIps $allowedIps = null): AuthTokens
    {
        if ($credentials instanceof ManualCredentials) {
            throw new ManualSignatureRequiredException('This client signs authentication requests manually: call createAuthenticationRequest(), have the request signed, then authenticateWithSignedRequest().', 401);
        }

        $challenge = $this->api->challenge();

        $operation = match (true) {
            $credentials instanceof CertificateCredentials => $this->api->submitSignedRequest(
                $credentials->signer->sign($this->requestXml->build($challenge->challenge, $context, $credentials->identifiedBy, $allowedIps)),
            ),
            $credentials instanceof KsefTokenCredentials => $this->submitToken($challenge, $context, $credentials, $allowedIps),
            default => throw new ConfigurationException(\sprintf('Unsupported credentials type "%s".', $credentials::class)),
        };

        return $this->finish($operation);
    }

    /**
     * Step 1 of manual signing: a fresh challenge and the unsigned request to hand to the signer.
     */
    public function prepare(ContextIdentifier $context, ManualCredentials $credentials, ?AllowedIps $allowedIps = null): AuthenticationRequest
    {
        $challenge = $this->api->challenge();

        return new AuthenticationRequest(
            $this->requestXml->build($challenge->challenge, $context, $credentials->identifiedBy, $allowedIps),
            $challenge->challenge,
            $challenge->timestamp,
            $challenge->timestamp->modify('+10 minutes'),
        );
    }

    /**
     * Step 2 of manual signing: submits the signed request, waits for KSeF to verify it and redeems the tokens.
     *
     * @throws AuthenticationException when KSeF refuses the signature or the identity
     */
    public function complete(string $signedXml): AuthTokens
    {
        return $this->finish($this->api->submitSignedRequest($signedXml));
    }

    private function finish(AuthOperation $operation): AuthTokens
    {
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
