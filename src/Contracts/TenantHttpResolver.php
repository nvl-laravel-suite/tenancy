<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Illuminate\Http\Request;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Selects a candidate tenant from a trusted HTTP request boundary.
 */
interface TenantHttpResolver
{
    /**
     * Resolve a candidate tenant identifier from the request.
     */
    public function resolve(Request $request): TenantId;
}
