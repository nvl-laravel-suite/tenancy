<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/**
 * @deprecated Use \Nvl\Support\Tenancy\ValueObjects\TenantId; retained for one major release.
 *
 * @api
 */
LegacyNeutralAlias::register(TenantId::class, 'Nvl\\Tenancy\\ValueObjects\\TenantId');
