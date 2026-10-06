<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Enums\TenantContextMode; retained for one major release. */
LegacyNeutralAlias::register(TenantContextMode::class, 'Nvl\\Tenancy\\Enums\\TenantContextMode');
