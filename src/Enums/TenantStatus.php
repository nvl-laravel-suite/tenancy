<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Enums\TenantStatus; retained for one major release. */
LegacyNeutralAlias::register(TenantStatus::class, 'Nvl\\Tenancy\\Enums\\TenantStatus');
