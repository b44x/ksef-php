<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

use B4x\Ksef\Auth\AccessTokenProvider;
use B4x\Ksef\Exception\AuthenticationException;

/**
 * Sends requests with a valid access token.
 *
 * When KSeF answers 401 the cached tokens are discarded and the request is repeated exactly once
 * with fresh tokens. A 401 is produced before any processing happens, so repeating even a
 * mutating request cannot duplicate it.
 */
final class AuthorizedClient
{
    public function __construct(
        private readonly Transport $transport,
        private readonly AccessTokenProvider $tokens,
    ) {}

    public function send(ApiRequest $request): ApiResponse
    {
        try {
            return $this->transport->send($request->withBearerToken($this->tokens->accessToken()));
        } catch (AuthenticationException $e) {
            if ($e->httpStatus !== 401) {
                throw $e;
            }
            $this->tokens->invalidate();

            return $this->transport->send($request->withBearerToken($this->tokens->accessToken()));
        }
    }
}
