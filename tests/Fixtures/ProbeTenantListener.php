<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Native queued listener fixture records the event's admitted scope. */
final class ProbeTenantListener implements ShouldQueue
{
    public function handle(ProbeTenantEvent $event): void
    {
        $event::observe('listener');
    }
}
