<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Nvl\Tenancy\Contracts\TenantQueuedJob;

/** Event arguments carry their producer envelope before native listener scheduling. */
final class ProbeTenantEvent implements TenantQueuedJob
{
    use QueueProbeCarrier;
}
