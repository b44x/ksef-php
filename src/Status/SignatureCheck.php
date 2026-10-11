<?php

declare(strict_types=1);

namespace B4x\Ksef\Status;

use DateTimeImmutable;

/**
 * The result of checking the XML signature of a document (for example a UPO).
 *
 * A valid signature proves the document was not changed since it was signed by the holder of the embedded
 * certificate. It proves *who* signed only when {@see self::$trusted} is true, which needs the certificate (or its
 * issuer) that you trust: see {@see Upo::verifySignature()}.
 */
final readonly class SignatureCheck
{
    /**
     * @param list<string> $problems why the signature is not valid; empty when it is
     * @param bool $trusted the embedded certificate is the one you pinned, or was issued by the one you pinned
     */
    public function __construct(
        public bool $valid,
        public array $problems = [],
        public bool $trusted = false,
        public ?string $signerSubject = null,
        public ?string $signerIssuer = null,
        public ?string $signerSerialNumber = null,
        public ?string $signerFingerprint = null,
        public ?DateTimeImmutable $certificateValidFrom = null,
        public ?DateTimeImmutable $certificateValidTo = null,
    ) {}
}
