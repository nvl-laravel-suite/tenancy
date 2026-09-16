<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Http\Request;
use Nvl\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Reconciles explicitly trusted route, header, and serving-host selectors in tests. */
final class StrictTenantHttpResolver implements TenantHttpResolver
{
    /** @param array<string, string> $hosts */
    public function __construct(private array $hosts = []) {}

    /** Require every supported selector to identify the same tenant. */
    public function resolve(Request $request): TenantId
    {
        $candidates = array_values(array_unique(array_filter([
            $request->route('tenant'), $request->header('X-Test-Tenant'), $this->hosts[$request->getHost()] ?? null,
        ])));
        if (count($candidates) !== 1 || ! is_string($candidates[0])) {
            throw new TenantBoundaryViolation;
        }

        return new TenantId($candidates[0]);
    }
}
