<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

/**
 * Where access and refresh tokens are kept between requests.
 *
 * PHP normally starts every request with an empty memory, so without a store each request would authenticate from
 * scratch (challenge, signature, polling, token redemption). With one, requests share the tokens until they expire.
 *
 * The stored value is the token pair itself: **a secret**. Keep it in a place only your application can read
 * (the bundled {@see FileTokenStore} uses owner-only permissions; with {@see Psr16TokenStore} use a private cache).
 * Keys are opaque hashes of the environment, the context and the credentials.
 */
interface TokenStore
{
    /** The value saved under the key, or null when there is none or it has expired. */
    public function get(string $key): ?string;

    /**
     * @param int $ttlSeconds how long the value is worth keeping (it is dropped after that)
     */
    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
