<?php

declare(strict_types=1);

namespace B4x\Ksef\Rr;

/** How the farmer is paid (`Platnosc`): by bank transfer, or another way described in words. */
final readonly class RrPayment
{
    private function __construct(
        public bool $transfer,
        public ?string $otherDescription,
        public ?string $farmerAccount,
        public ?string $buyerAccount,
    ) {}

    /** Bank transfer; the accounts are optional full account numbers. */
    public static function transfer(?string $farmerAccount = null, ?string $buyerAccount = null): self
    {
        return new self(true, null, $farmerAccount, $buyerAccount);
    }

    /** Any other form, for example "cash". */
    public static function other(string $description, ?string $farmerAccount = null, ?string $buyerAccount = null): self
    {
        return new self(false, $description, $farmerAccount, $buyerAccount);
    }
}
