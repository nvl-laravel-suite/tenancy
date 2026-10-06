<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/**
 * @deprecated Use \Nvl\Support\Tenancy\Contracts\TenantMembershipAccess; retained for one major release.
 *
 * @api
 */
LegacyNeutralAlias::register(TenantMembershipAccess::class, 'Nvl\\Tenancy\\Contracts\\TenantMembershipAccess');
