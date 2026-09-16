<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

/** Local channel records execution without contacting any recipient. */
final class ProbeTenantChannel
{
    public function send(object $notifiable, ProbeTenantNotification $notification): void
    {
        $notification::observe('notification');
    }
}
