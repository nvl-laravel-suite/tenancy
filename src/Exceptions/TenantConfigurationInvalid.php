<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid; retained for one major release. */
LegacyNeutralAlias::register(TenantConfigurationInvalid::class, 'Nvl\\Tenancy\\Exceptions\\TenantConfigurationInvalid');
