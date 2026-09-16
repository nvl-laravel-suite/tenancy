<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use InvalidArgumentException;
use Nvl\Tenancy\Enums\TenantContextMode;

/**
 * Captures one immutable tenant context state.
 */
final readonly class TenantContextSnapshot
{
    /**
     * Create an immutable context snapshot.
     */
    public function __construct(
        public TenantContextMode $mode,
        public ?TenantId $tenantId = null,
    ) {
        if ($this->mode === TenantContextMode::Tenant && $this->tenantId === null) {
            throw new InvalidArgumentException('Tenant mode requires a tenant identifier.');
        }

        if ($this->mode !== TenantContextMode::Tenant && $this->tenantId !== null) {
            throw new InvalidArgumentException('Only tenant mode may carry a tenant identifier.');
        }
    }
}
