<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

/**
 * Carries a verified tenant, site, and canonical public origin.
 */
final readonly class TenantSiteContext
{
    /**
     * Create a verified public-site context.
     */
    public function __construct(
        public TenantId $tenantId,
        public string $site,
        public string $canonicalOrigin,
    ) {}
}
