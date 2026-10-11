<?php

declare(strict_types=1);

namespace B4x\Ksef\Auth;

use B4x\Ksef\Exception\ConfigurationException;

/**
 * Stores tokens in files of a private directory (created with mode 0700, files 0600, written atomically).
 *
 * Point it at a directory that is not web-accessible and not shared with other users, for example
 * `/var/lib/myapp/ksef`. Use {@see Psr16TokenStore} instead when your application already has a private cache.
 */
final class FileTokenStore implements TokenStore
{
    public function __construct(private readonly string $directory)
    {
        if ($directory === '') {
            throw new ConfigurationException('The token directory must not be empty.');
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!\is_array($data) || !\is_string($data['value'] ?? null) || !\is_int($data['expiresAt'] ?? null) || $data['expiresAt'] <= time()) {
            @unlink($path);

            return null;
        }

        return $data['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new ConfigurationException(\sprintf('Cannot create the token directory "%s".', $this->directory));
        }

        $path = $this->path($key);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $previousUmask = umask(0o077);
        try {
            $written = file_put_contents($temporary, json_encode(['value' => $value, 'expiresAt' => time() + $ttlSeconds], JSON_THROW_ON_ERROR));
        } finally {
            umask($previousUmask);
        }
        if ($written === false || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new ConfigurationException(\sprintf('Cannot write the token file in "%s".', $this->directory));
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        // Keys are hashes; refuse anything else so a key can never leave the directory.
        if (preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
            throw new ConfigurationException('Invalid token store key.');
        }

        return rtrim($this->directory, '/\\') . \DIRECTORY_SEPARATOR . 'ksef-' . $key . '.json';
    }
}
