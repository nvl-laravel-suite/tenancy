<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Closure;
use Nvl\Tenancy\Contracts\TenantContextParticipant;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;

/** Executes test-selected participant behavior against the real runner. */
class TestContextParticipant implements TenantContextParticipant
{
    /** @param Closure(TenantContextSnapshot): (Closure(): void) $enter */
    public function __construct(private Closure $enter) {}

    /**
     * Enter the test integration state.
     *
     * @return Closure(): void
     */
    public function enter(TenantContextSnapshot $next): Closure
    {
        return ($this->enter)($next);
    }
}
