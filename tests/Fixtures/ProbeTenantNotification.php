<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Nvl\Tenancy\Contracts\TenantQueuedJob;

/** Native queued notification fixture with a local recording channel. */
final class ProbeTenantNotification extends Notification implements ShouldQueue, TenantQueuedJob
{
    use Queueable;
    use QueueProbeCarrier;

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [ProbeTenantChannel::class];
    }
}
