<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Closure;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;

/** Installs scope-local integration state and provides its restoration. */
interface TenantContextParticipant
{
    /**
     * Install the next context, restoring partial state before throwing on failure.
     *
     * @return Closure(): void
     */
    public function enter(TenantContextSnapshot $next): Closure;
}
