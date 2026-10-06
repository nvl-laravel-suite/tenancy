<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/**
 * @deprecated Use \Nvl\Support\Tenancy\Contracts\TenantDirectory; retained for one major release.
 *
 * @api
 */
LegacyNeutralAlias::register(TenantDirectory::class, 'Nvl\\Tenancy\\Contracts\\TenantDirectory');
