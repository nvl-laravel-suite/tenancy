<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Support\Tenancy\Services\TenantQueuePayload;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Services\TenantQueuePayload; retained for one major release. */
LegacyNeutralAlias::register(TenantQueuePayload::class, 'Nvl\\Tenancy\\Services\\TenantQueuePayload');
