<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

/** Reports bounded ownership/schema validation failures without record payloads. */
final readonly class TenantVerification
{
    /** @param list<string> $errors */
    public function __construct(public array $errors) {}

    /** Determine whether all ownership checks passed. */
    public function passed(): bool
    {
        return $this->errors === [];
    }
}
