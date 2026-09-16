<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Producer and consumer locks derive from the same immutable captured tenant. */
final class UniqueProbeTenantJob extends ProbeTenantJob implements ShouldBeUnique
{
    public function uniqueId(): string
    {
        return $this->envelope->context->tenantId?->value.':'.$this->label;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->uniqueId())];
    }
}
