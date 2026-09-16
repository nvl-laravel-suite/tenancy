<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** A queued tenant command captures this immutable envelope before native dispatch scheduling. */
interface TenantQueuedJob
{
    /** Return the envelope captured when the command was constructed in its producer scope. */
    public function tenantJobEnvelope(): TenantJobEnvelope;
}
