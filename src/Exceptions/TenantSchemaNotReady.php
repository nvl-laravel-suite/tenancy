<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Nvl\Support\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Exceptions\TenantSchemaNotReady; retained for one major release. */
LegacyNeutralAlias::register(TenantSchemaNotReady::class, 'Nvl\\Tenancy\\Exceptions\\TenantSchemaNotReady');
