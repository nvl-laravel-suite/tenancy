<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Carries a canonical tenant UUID across tenancy boundaries.
 */
final readonly class TenantId
{
    public string $value;

    /**
     * Create a canonical tenant UUID.
     *
     * @throws InvalidArgumentException When the identifier is not a UUID
     */
    public function __construct(string $value)
    {
        $canonical = Str::lower(trim($value));

        if (! Str::isUuid($canonical)) {
            throw new InvalidArgumentException('Tenant identifiers must be valid UUIDs.');
        }

        $this->value = $canonical;
    }
}
