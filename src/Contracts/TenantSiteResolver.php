<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Illuminate\Http\Request;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

/**
 * Resolves a verified tenant and site context for public requests.
 */
interface TenantSiteResolver
{
    /**
     * Resolve a verified public-site context from the request.
     */
    public function resolve(Request $request): TenantSiteContext;
}
