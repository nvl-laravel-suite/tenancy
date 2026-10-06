<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Support\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Services\EffectiveTenantConnection; retained for one major release. */
LegacyNeutralAlias::register(EffectiveTenantConnection::class, 'Nvl\\Tenancy\\Services\\EffectiveTenantConnection');
