<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Exception\AuthenticationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Throwable;

/**
 * Hands out a valid access token, authenticating or refreshing transparently.
 *
 * - First use authenticates.
 * - A token that expires within the safety margin is refreshed with the refresh token.
 * - When the refresh token is expired or rejected, a full authentication is performed.
 *
 * Tokens live in memory and, when a {@see TokenStore} is given, are shared through it so that later requests (new
 * PHP processes) reuse them. Parallel processes may refresh at the same time; the last write wins, which is harmless.
 *
 * @internal Not part of the public API: it may change in any release. Use {@see \B4x\Ksef\KsefClient}.
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
        private readonly ?TokenStore $store = null,
        private readonly string $storeKey = '',
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** A bearer token that is valid for at least the configured margin. */
    public function accessToken(): string
    {
        $now = $this->clock->now();

        $tokens = $this->tokens ?? $this->load();
        if ($tokens === null || $tokens->refreshToken->expiresWithin($now, 0)) {
            return $this->authenticate()->accessToken->token;
        }

        if ($tokens->accessToken->expiresWithin($now, $this->refreshMarginSeconds)) {
            try {
                $tokens = new AuthTokens($this->api->refresh($tokens->refreshToken), $tokens->refreshToken);
                $this->save($tokens);
                $this->logger->debug('KSeF access token refreshed.');
            } catch (AuthenticationException) {
                $this->logger->notice('KSeF refresh token rejected; authenticating again.');

                return $this->authenticate()->accessToken->token;
            }
        }
        $this->tokens = $tokens;

        return $tokens->accessToken->token;
    }

    /** Drops cached tokens, for example after the API answered 401 to a request. */
    public function invalidate(): void
    {
        $this->tokens = null;
        $this->store?->delete($this->storeKey);
    }

    private function authenticate(): AuthTokens
    {
        $tokens = $this->authenticator->authenticate($this->context, $this->credentials, $this->allowedIps);
        $this->tokens = $tokens;
        $this->save($tokens);

        return $tokens;
    }

    private function load(): ?AuthTokens
    {
        if ($this->store === null) {
            return null;
        }

        try {
            $raw = $this->store->get($this->storeKey);
            $data = $raw === null ? null : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                return null;
            }
            $tokens = new AuthTokens(TokenInfo::fromApi($data['access'] ?? null), TokenInfo::fromApi($data['refresh'] ?? null));
        } catch (Throwable) {
            $this->logger->notice('Stored KSeF tokens are unreadable; authenticating again.');
            $this->store->delete($this->storeKey);

            return null;
        }
        $this->logger->debug('KSeF tokens loaded from the token store.');

        return $tokens;
    }

    private function save(AuthTokens $tokens): void
    {
        if ($this->store === null) {
            return;
        }

        $ttl = $tokens->refreshToken->validUntil->getTimestamp() - $this->clock->now()->getTimestamp();
        if ($ttl <= 0) {
            return;
        }
        try {
            $this->store->set($this->storeKey, json_encode([
                'access' => ['token' => $tokens->accessToken->token, 'validUntil' => $tokens->accessToken->validUntil->format(\DATE_ATOM)],
                'refresh' => ['token' => $tokens->refreshToken->token, 'validUntil' => $tokens->refreshToken->validUntil->format(\DATE_ATOM)],
            ], JSON_THROW_ON_ERROR), $ttl);
        } catch (Throwable $e) {
            // A broken store must never break the request: the tokens are still held in memory.
            $this->logger->warning('KSeF tokens could not be saved to the token store.', ['error' => $e::class]);
        }
    }
}
