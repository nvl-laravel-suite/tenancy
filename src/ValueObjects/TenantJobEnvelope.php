<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/**
 * @deprecated Use \Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope; retained for one major release.
 *
 * @api
 */
LegacyNeutralAlias::register(TenantJobEnvelope::class, 'Nvl\\Tenancy\\ValueObjects\\TenantJobEnvelope');
