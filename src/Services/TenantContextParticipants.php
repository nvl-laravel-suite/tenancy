<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Support\Tenancy\Services\TenantContextParticipants;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Services\TenantContextParticipants; retained for one major release. */
LegacyNeutralAlias::register(TenantContextParticipants::class, 'Nvl\\Tenancy\\Services\\TenantContextParticipants');
