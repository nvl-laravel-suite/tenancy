<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Marks actual execution so queue-denial tests detect escaped callbacks. */
final class MaintenanceProbeJob implements ShouldQueue
{
    use Queueable;

    public static int $executions = 0;

    /** Record actual worker execution. */
    public function handle(): void
    {
        self::$executions++;
    }
}
