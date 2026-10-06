<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\ValueObjects\TenantDescriptor; retained for one major release. */
LegacyNeutralAlias::register(TenantDescriptor::class, 'Nvl\\Tenancy\\ValueObjects\\TenantDescriptor');
