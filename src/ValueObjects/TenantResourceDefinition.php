<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition; retained for one major release. */
LegacyNeutralAlias::register(TenantResourceDefinition::class, 'Nvl\\Tenancy\\ValueObjects\\TenantResourceDefinition');
