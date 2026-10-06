<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Contracts\TenantContextParticipant;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Contracts\TenantContextParticipant; retained for one major release. */
LegacyNeutralAlias::register(TenantContextParticipant::class, 'Nvl\\Tenancy\\Contracts\\TenantContextParticipant');
