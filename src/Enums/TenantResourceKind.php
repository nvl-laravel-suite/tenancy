<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

use Nvl\Support\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Enums\TenantResourceKind; retained for one major release. */
LegacyNeutralAlias::register(TenantResourceKind::class, 'Nvl\\Tenancy\\Enums\\TenantResourceKind');
