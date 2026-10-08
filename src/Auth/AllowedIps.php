<?php

declare(strict_types=1);

namespace Ksef\Auth;

use Ksef\Exception\ValidationException;

/**
 * Optional IP allow-list bound to the issued access token (KSeF `AuthorizationPolicy`).
 * Requests from other addresses are answered with 403 `ip-not-allowed`.
 */
final readonly class AllowedIps
{
    /**
     * @param list<string> $addresses IPv4 addresses, for example 192.168.0.10
     * @param list<string> $ranges IPv4 ranges, for example 10.0.0.1-10.0.0.254
     * @param list<string> $masks IPv4 CIDR masks, for example 192.168.1.0/24
     */
    public function __construct(
        public array $addresses = [],
        public array $ranges = [],
        public array $masks = [],
    ) {
        $octet = '(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';
        $ip = "$octet(\\.$octet){3}";

        $violations = [];
        foreach ([[$addresses, "/^$ip$/", 'address'], [$ranges, "/^$ip-$ip$/", 'range'], [$masks, "/^$ip\\/(\\d|[12]\\d|3[0-2])$/", 'mask']] as [$values, $pattern, $label]) {
            if (\count($values) > 10) {
                $violations[] = \sprintf('At most 10 IPv4 %ss are allowed.', $label);
            }
            foreach ($values as $value) {
                if (preg_match($pattern, $value) !== 1) {
                    $violations[] = \sprintf('"%s" is not a valid IPv4 %s.', $value, $label);
                }
            }
        }
        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }
    }

    public function isEmpty(): bool
    {
        return $this->addresses === [] && $this->ranges === [] && $this->masks === [];
    }

    /**
     * @return array{ip4Addresses: list<string>, ip4Ranges: list<string>, ip4Masks: list<string>}
     */
    public function toArray(): array
    {
        return ['ip4Addresses' => $this->addresses, 'ip4Ranges' => $this->ranges, 'ip4Masks' => $this->masks];
    }
}
