<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Nvl\Tenancy\Contracts\TenantQueuedJob;

/** Native queued mail fixture using Laravel's local array transport. */
final class ProbeTenantMail extends Mailable implements ShouldQueue, TenantQueuedJob
{
    use Queueable;
    use QueueProbeCarrier;

    public function build(): self
    {
        self::observe('mail');

        return $this->html('Tenant mail probe')->subject('Probe');
    }
}
