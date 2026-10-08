<?php

declare(strict_types=1);

namespace Ksef\Auth;

use Ksef\Exception\AuthenticationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

/**
 * Hands out a valid access token, authenticating or refreshing transparently.
 *
 * - First use authenticates.
 * - A token that expires within the safety margin is refreshed with the refresh token.
 * - When the refresh token is expired or rejected, a full authentication is performed.
 *
 * Tokens are kept in memory only. The class is not safe for concurrent use by parallel processes;
 * every process authenticates on its own.
 */
final class AccessTokenProvider
{
    private ?AuthTokens $tokens = null;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly AuthApi $api,
        private readonly ContextIdentifier $context,
        #[SensitiveParameter]
        private readonly Credentials $credentials,
        private readonly ClockInterface $clock,
        private readonly ?AllowedIps $allowedIps = null,
        private readonly int $refreshMarginSeconds = 60,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** A bearer token that is valid for at least the configured margin. */
    public function accessToken(): string
    {
        $now = $this->clock->now();

        if ($this->tokens === null || $this->tokens->refreshToken->expiresWithin($now, 0)) {
            return $this->authenticate()->accessToken->token;
        }

        if ($this->tokens->accessToken->expiresWithin($now, $this->refreshMarginSeconds)) {
            try {
                $this->tokens = new AuthTokens($this->api->refresh($this->tokens->refreshToken), $this->tokens->refreshToken);
                $this->logger->debug('KSeF access token refreshed.');
            } catch (AuthenticationException) {
                $this->logger->notice('KSeF refresh token rejected; authenticating again.');

                return $this->authenticate()->accessToken->token;
            }
        }

        return $this->tokens->accessToken->token;
    }

    /** Drops cached tokens, for example after the API answered 401 to a request. */
    public function invalidate(): void
    {
        $this->tokens = null;
    }

    private function authenticate(): AuthTokens
    {
        return $this->tokens = $this->authenticator->authenticate($this->context, $this->credentials, $this->allowedIps);
    }
}
