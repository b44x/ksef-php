<?php

declare(strict_types=1);

namespace B4x\Ksef\Doctor;

/** All results of a {@see Doctor} run. */
final readonly class DoctorReport
{
    /**
     * @param list<CheckResult> $results
     */
    public function __construct(public array $results) {}

    public function hasFailures(): bool
    {
        return $this->count(CheckStatus::Failed) > 0;
    }

    public function count(CheckStatus $status): int
    {
        return \count(array_filter($this->results, static fn(CheckResult $r): bool => $r->status === $status));
    }

    /**
     * @return array{ok: bool, results: list<array{name: string, status: string, message: string, details: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'ok' => !$this->hasFailures(),
            'results' => array_map(static fn(CheckResult $r): array => ['name' => $r->name, 'status' => $r->status->value, 'message' => $r->message, 'details' => $r->details], $this->results),
        ];
    }
}
