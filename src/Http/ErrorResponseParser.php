<?php

declare(strict_types=1);

namespace B4x\Ksef\Http;

use B4x\Ksef\Exception\ApiException;
use B4x\Ksef\Exception\AuthenticationException;
use B4x\Ksef\Exception\AuthorizationException;
use B4x\Ksef\Exception\RateLimitException;
use B4x\Ksef\Exception\ServerException;
use JsonException;

/**
 * Turns an error response into the matching exception.
 *
 * Understands both error formats KSeF can produce: Problem Details (requested through the
 * X-Error-Format header) and the legacy "exception" envelope.
 *
 * @internal
 */
final class ErrorResponseParser
{
    public function parse(int $status, string $body, ?int $retryAfter): ApiException
    {
        $data = $this->decode($body);
        $errors = $this->extractErrors($data);
        $traceId = $this->string($data['traceId'] ?? null) ?? $this->legacyReference($data);
        $detail = $this->string($data['detail'] ?? null) ?? $this->string($data['title'] ?? null);
        $message = $this->message($status, $detail, $errors);

        return match (true) {
            $status === 401 => new AuthenticationException($message, $status, $errors, $traceId),
            $status === 403 => new AuthorizationException($message, $status, $errors, $traceId, $this->string($data['reasonCode'] ?? null)),
            $status === 429 => new RateLimitException($message, $errors, $traceId, $retryAfter),
            $status >= 500 => new ServerException($message, $status, $errors, $traceId),
            default => new ApiException($message, $status, $errors, $traceId),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> */
        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<ApiError>
     */
    private function extractErrors(array $data): array
    {
        $errors = [];

        $problemErrors = $data['errors'] ?? null;
        if (\is_array($problemErrors)) {
            foreach ($problemErrors as $entry) {
                if (\is_array($entry)) {
                    $errors[] = $this->toError($entry['code'] ?? null, $entry['description'] ?? null, $entry['details'] ?? null);
                }
            }
        }

        $exception = $data['exception'] ?? null;
        if (\is_array($exception) && \is_array($exception['exceptionDetailList'] ?? null)) {
            foreach ($exception['exceptionDetailList'] as $entry) {
                if (\is_array($entry)) {
                    $errors[] = $this->toError($entry['exceptionCode'] ?? null, $entry['exceptionDescription'] ?? null, $entry['details'] ?? null);
                }
            }
        }

        return $errors;
    }

    private function toError(mixed $code, mixed $description, mixed $details): ApiError
    {
        $list = [];
        if (\is_array($details)) {
            foreach ($details as $detail) {
                if (\is_string($detail)) {
                    $list[] = $detail;
                }
            }
        }

        return new ApiError(\is_int($code) ? $code : null, $this->string($description) ?? '', $list);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function legacyReference(array $data): ?string
    {
        $exception = $data['exception'] ?? null;

        return \is_array($exception) ? $this->string($exception['referenceNumber'] ?? null) : null;
    }

    /**
     * @param list<ApiError> $errors
     */
    private function message(int $status, ?string $detail, array $errors): string
    {
        $message = \sprintf('KSeF API error (HTTP %d)', $status);
        $parts = [];
        if ($detail !== null) {
            $parts[] = $detail;
        }
        foreach ($errors as $error) {
            $parts[] = trim(\sprintf('[%s] %s %s', $error->code ?? '?', $error->description, implode('; ', $error->details)));
        }

        return $parts === [] ? $message : $message . ': ' . implode(' | ', $parts);
    }

    private function string(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
