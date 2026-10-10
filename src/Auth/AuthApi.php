<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Exception\MalformedResponseException;
use B4x\Ksef\Http\ApiRequest;
use B4x\Ksef\Http\RetryMode;
use B4x\Ksef\Http\Transport;
use DateTimeImmutable;
use Exception;

/**
 * Thin, typed wrapper over the `/auth/*` endpoints. Contains no flow logic; see {@see Authenticator}.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
 */
final class AuthApi
{
    public function __construct(private readonly Transport $transport) {}

    public function challenge(): AuthChallenge
    {
        $data = $this->transport->send(ApiRequest::post('/auth/challenge', null, null, RetryMode::Safe))->json();

        $challenge = $data['challenge'] ?? null;
        $timestamp = $data['timestamp'] ?? null;
        $timestampMs = $data['timestampMs'] ?? null;
        if (!\is_string($challenge) || !\is_string($timestamp) || !\is_int($timestampMs)) {
            throw new MalformedResponseException('The authentication challenge has an unexpected structure.');
        }

        try {
            return new AuthChallenge($challenge, new DateTimeImmutable($timestamp), $timestampMs);
        } catch (Exception $e) {
            throw new MalformedResponseException('The authentication challenge has an invalid timestamp.', 0, $e);
        }
    }

    /**
     * Starts certificate authentication with a XAdES-signed AuthTokenRequest.
     */
    public function submitSignedRequest(string $signedXml): AuthOperation
    {
        $response = $this->transport->send(ApiRequest::post('/auth/xades-signature', $signedXml, null, RetryMode::RateLimitOnly, contentType: 'application/xml'));

        return $this->operation($response->json());
    }

    /**
     * Starts KSeF-token authentication.
     */
    public function submitKsefToken(
        string $challenge,
        ContextIdentifier $context,
        string $encryptedTokenBase64,
        ?string $publicKeyId = null,
        ?AllowedIps $allowedIps = null,
    ): AuthOperation {
        $body = [
            'challenge' => $challenge,
            'contextIdentifier' => $context->toArray(),
            'encryptedToken' => $encryptedTokenBase64,
        ];
        if ($publicKeyId !== null) {
            $body['publicKeyId'] = $publicKeyId;
        }
        if ($allowedIps !== null && !$allowedIps->isEmpty()) {
            $body['authorizationPolicy'] = ['allowedIps' => $allowedIps->toArray()];
        }

        return $this->operation($this->transport->send(ApiRequest::post('/auth/ksef-token', $body, null, RetryMode::RateLimitOnly))->json());
    }

    public function status(AuthOperation $operation): AuthStatus
    {
        $data = $this->transport->send(ApiRequest::get('/auth/' . rawurlencode($operation->referenceNumber), $operation->authenticationToken->token))->json();

        $status = $data['status'] ?? null;
        if (!\is_array($status) || !\is_int($status['code'] ?? null) || !\is_string($status['description'] ?? null)) {
            throw new MalformedResponseException('The authentication status has an unexpected structure.');
        }

        $details = [];
        if (\is_array($status['details'] ?? null)) {
            foreach ($status['details'] as $detail) {
                if (\is_string($detail)) {
                    $details[] = $detail;
                }
            }
        }

        $method = $data['authenticationMethod'] ?? null;

        return new AuthStatus($status['code'], $status['description'], $details, \is_string($method) ? $method : null);
    }

    /**
     * Exchanges the temporary authentication token for the access/refresh token pair.
     * KSeF allows this exactly once per operation.
     */
    public function redeem(AuthOperation $operation): AuthTokens
    {
        $data = $this->transport->send(ApiRequest::post('/auth/token/redeem', null, $operation->authenticationToken->token, RetryMode::RateLimitOnly))->json();

        return new AuthTokens(TokenInfo::fromApi($data['accessToken'] ?? null), TokenInfo::fromApi($data['refreshToken'] ?? null));
    }

    public function refresh(TokenInfo $refreshToken): TokenInfo
    {
        $data = $this->transport->send(ApiRequest::post('/auth/token/refresh', null, $refreshToken->token, RetryMode::RateLimitOnly))->json();

        return TokenInfo::fromApi($data['accessToken'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function operation(array $data): AuthOperation
    {
        $reference = $data['referenceNumber'] ?? null;
        if (!\is_string($reference) || $reference === '') {
            throw new MalformedResponseException('The authentication operation has no reference number.');
        }

        return new AuthOperation($reference, TokenInfo::fromApi($data['authenticationToken'] ?? null));
    }
}
